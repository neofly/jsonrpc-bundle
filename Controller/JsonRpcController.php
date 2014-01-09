<?php

namespace Wa72\JsonRpcBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerAware;

/**
 * Controller for executing JSON-RPC 2.0 requests
 * see http://www.jsonrpc.org/specification
 *
 * Only functions of services registered in the DI container may be called.
 *
 * The constructor expects the DI container and a configuration array where
 * the mapping from the jsonrpc method names to service methods is defined:
 *
 * $config = array(
 *   'functions' => array(
 *      'myfunction1' => array(
 *          'service' => 'mybundle.servicename',
 *          'method' => 'methodofservice'
 *      ),
 *      'anotherfunction' => array(
 *          'service' => 'mybundle.foo',
 *          'method' => 'bar'
 *      )
 *   )
 * );
 *
 * A method name "myfunction1" in the RPC request will then call
 * $this->container->get('mybundle.servicename')->methodofservice()
 *
 * @license MIT
 * @author Christoph Singer
 *
 */
class JsonRpcController extends ContainerAware
{
    const PARSE_ERROR = -32700;
    const INVALID_REQUEST = -32600;
    const METHOD_NOT_FOUND = -32601;
    const INVALID_PARAMS = -32602;
    const INTERNAL_ERROR = -32603;

    /**
     * @var array $config
     */
    private $config;

    /**
     * @var \JMS\Serializer\SerializationContext
     */
    private $serializationContext;

    /**
     * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
     * @param array $config Associative array for configuration, expects at least a key "functions"
     *
     */
    public function __construct($container, $config)
    {
        $this->config = $config;
        $this->setContainer($container);
    }
    
    /**
     * @param Request $httprequest
     * @return Response
     */
    public function execute(Request $httpRequest)
    {
        $json = $httpRequest->getContent();
        $request = (object)json_decode($json, true);
        
        $response = $this->handleRpc($request);
        
        if ($this->container->has('jms_serializer')) {
            $response = $this->container->get('jms_serializer')->serialize($response, 'json', $this->serializationContext);
        } else {
            $response = json_encode($response);
        }
        
        return new Response($response, 200, array('Content-Type' => 'application/json'));
    }

    /**
     * @param stdClass $request
     * @return array
     */
    public function handleRpc($request)
    {
        $requestId = (isset($request->id) ? $request->id : null);
        
        if ($request === null) {
            return $this->getErrorResponse(self::PARSE_ERROR, null);
        } else if (!(isset($request->jsonrpc) && isset($request->method) && $request->jsonrpc == '2.0')) {
            return $this->getErrorResponse(self::INVALID_REQUEST, $requestId);
        } else if (!in_array($request->method, array_keys($this->config['functions']))) {
            return $this->getErrorResponse(self::METHOD_NOT_FOUND, $requestId);
        }
        $service = $this->container->get($this->config['functions'][$request->method]['service']);
        $method = $this->config['functions'][$request->method]['method'];
        $params = (isset($request->params) ? $request->params : array());
        if (is_callable(array($service, $method))) {
            $r = new \ReflectionMethod($service, $method);
            if (is_array($params)) {
                if (!(count($params) >= $r->getNumberOfRequiredParameters()
                    && count($params) <= $r->getNumberOfParameters())
                ) {
                    return $this->getErrorResponse(self::INVALID_PARAMS, $requestId,
                        sprintf('Number of given parameters (%d) does not match the number of expected parameters (%d required, %d total)',
                            count($params), $r->getNumberOfRequiredParameters(), $r->getNumberOfParameters()));
                }
            } elseif (is_object($params)) {
                $rps = $r->getParameters();
                $newparams = array();
                foreach ($rps as $i => $rp) {
                    /* @var \ReflectionParameter $rp */
                    $name = $rp->name;
                    if (!isset($params->$name) && !$rp->isOptional()) {
                        return $this->getErrorResponse(self::INVALID_PARAMS, $requestId,
                            sprintf('Parameter %s is missing', $name));
                    }
                    if (isset($params->$name)) {
                        $newparams[$i] = $params->$name;
                    } else {
                        $newparams[$i] = null;
                    }
                }
                $params = $newparams;
            }
            try {
                $result = call_user_func_array(array($service, $method), $params);
            } catch (\Exception $e) {
                return $this->getExceptionResponse($requestId, $e);
            }
            $response = array('jsonrpc' => '2.0');
            $response['result'] = $result;
            $response['id'] = $requestId;

            return $response;
        } else {
            return $this->getErrorResponse(self::METHOD_NOT_FOUND, $requestId);
        }
    }

    protected function getError($code)
    {
        $message = '';
        switch ($code) {
            case self::PARSE_ERROR:
                $message = 'Parse error';
                break;
            case self::INVALID_REQUEST:
                $message = 'Invalid request';
                break;
            case self::METHOD_NOT_FOUND:
                $message = 'Method not found';
                break;
            case self::INVALID_PARAMS:
                $message = 'Invalid params';
                break;
            case self::INTERNAL_ERROR:
                $message = 'Internal error';
                break;
        }
        return array('code' => $code, 'message' => $message);
    }

    protected function getErrorResponse($code, $id, $data = null)
    {
        $response = array('jsonrpc' => '2.0');
        $response['error'] = $this->getError($code);
        if ($data != null) $response['error']['data'] = $data;
        $response['id'] = $id;
        return $response;
    }

    protected function getExceptionResponse($id, \Exception $e)
    {
        $response = array('jsonrpc' => '2.0', 'id' => $id);
        $response['error'] = array();
        $response['error']['code'] = $e->getCode();
        $response['error']['message'] = $e->getMessage();
        
        $callable = array($e, 'getData');
        if (is_callable($callable)) {
            $response['error']['data'] = call_user_func($callable);
            if ($response['error']['data']) {
                foreach($response['error']['data'] as $key=>$data) 
                {
                  $response['error']['data'][$key] = $this->container->get('translator')->trans($data);
                }
            }
        }
        
        return $response;
    }
    
    /**
     * Set SerializationContext for using with jms_serializer
     *
     * @param \JMS\Serializer\SerializationContext $context
     */
    public function setSerializationContext($context)
    {
        $this->serializationContext = $context;
    }
}
