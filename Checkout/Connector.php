<?php

/**
 * Copyright 2012 Klarna AB
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * File containing the Klarna_Checkout_Connector class
 *
 * PHP version 5.3
 *
 * @category  Payment
 * @package   Klarna_Checkout
 * @author    Klarna <support@klarna.com>
 * @copyright 2012 Klarna AB
 * @license   http://www.apache.org/licenses/LICENSE-2.0 Apache license v2.0
 * @link      http://integration.klarna.com/
 */

require_once 'Checkout/Exception.php';

/**
 * Implementation of the connector interface
 *
 * @category  Payment
 * @package   Klarna_Checkout
 * @author    Rickard D. <rickard.dybeck@klarna.com>
 * @author    Christer G. <christer.gustavsson@klarna.com>
 * @copyright 2012 Klarna AB
 * @license   http://www.apache.org/licenses/LICENSE-2.0 Apache license v2.0
 * @link      http://integration.klarna.com/
 */
class Klarna_Checkout_Connector implements Klarna_Checkout_ConnectorInterface
{

    /**
     * Klarna_Checkout_HTTP_HTTPInterface Implementation
     *
     * @var Klarna_Checkout_HttpInterface
     */
    protected $http;

    /**
     * Digester class
     *
     * @var Klarna_Checkout_Digester
     */
    protected $digester;

    /**
     * Shared Secret used to sign requests
     *
     * @var string
     */
    private $_secret;

    /**
     * Create a new Checkout Connector
     *
     * @param Klarna_Checkout_HTTP_HTTPInterface $http     HTTP Implementation
     * @param Klarna_Checkout_Digester           $digester Digest Generator
     * @param string                             $secret   string used to sign
     *                                                     requests
     */
    public function __construct(
        Klarna_Checkout_HTTP_HTTPInterface $http,
        Klarna_Checkout_Digester $digester,
        $secret
    ) {
        $this->http = $http;
        $this->digester = $digester;
        $this->_secret = $secret;
    }

    /**
     * Applying the method on the specific resource
     *
     * @param string                            $method   Http methods
     * @param Klarna_Checkout_ResourceInterface $resource resource
     * @param array                             $options  Options
     *
     * @return mixed
     */
    public function apply(
        $method,
        Klarna_Checkout_ResourceInterface $resource,
        array $options = null
    ) {
        switch ($method) {
        case 'GET':
        case 'POST':
            return $this->handle($method, $resource, $options);
        default:
            throw new InvalidArgumentException(
                "{$method} is not a valid HTTP method"
            );
        }
    }

    /**
     * Set content (headers, payload) on a request
     *
     * @param Klarna_Checkout_ResourceInterface $resource Klarna Checkout Resource
     * @param string                            $method   HTTP Method
     * @param string                            $payload  Payload to send with the
     *                                                    request
     * @param string                            $url      URL for request
     *
     * @return Klarna_Checkout_HTTP_Request
     */
    protected function createRequest(
        Klarna_Checkout_ResourceInterface $resource,
        $method,
        $payload,
        $url
    ) {
        // Generate the digest string
        $digest = $this->digester->createDigest($payload . $this->_secret);

        $request = $this->http->createRequest($url);

        $request->setMethod($method);

        // Set HTTP Headers
        $request->setHeader('Authorization', "Klarna {$digest}");
        $request->setHeader('Accept', $resource->getContentType());
        if (strlen($payload) > 0) {
            $request->setHeader('Content-Type', $resource->getContentType());
            $request->setData($payload);
        }

        return $request;
    }

    /**
     * Get the url to use
     *
     * @param Klarna_Checkout_ResourceInterface $resource resource
     * @param array                             $options  Options
     *
     * @return string Url to use for HTTP requests
     */
    protected function getUrl(
        Klarna_Checkout_ResourceInterface $resource, array $options = null
    ) {
        $url = '';

        if ($options !== null && array_key_exists('url', $options)) {
            $url = $options['url'];
        } else {
            $url = $resource->getLocation();
        }

        return $url;
    }

    /**
     * Throw an exception if the server responds with an error code.
     *
     * @param Klarna_Checkout_HTTP_Response $result HTTP Response object
     *
     * @throws Klarna_Checkout_HTTP_Status_Exception
     * @return void
     */
    protected function verifyResponse(Klarna_Checkout_HTTP_Response $result)
    {
        // Error Status Code recieved. Throw an exception.
        if ($result->getStatus() >= 400 && $result->getStatus() <= 599) {
            throw new Klarna_Checkout_HTTP_Status_Exception(
                $result->getData(), $result->getStatus()
            );
        }
    }

    protected function handleResponse(
        Klarna_Checkout_HTTP_Response $result,
        Klarna_Checkout_ResourceInterface $resource
    ) {
        switch ($result->getStatus()) {
        case 301:
            // Update location and fallthrough
            $resource->setLocation($result->getHeader('Location'));
        case 302:
            // Don't fallthrough for other than GET
            if ($result->getRequest()->getMethod() !== 'GET') {
                break;
            }
        case 303:
            // Follow redirect
            return $this->handle(
                'GET',
                $resource,
                array('url' => $result->getHeader('Location'))
            );
        case 201:
            // Update Location
            $resource->setLocation($result->getHeader('Location'));
            break;
        case 200:
            // Update Data on resource
            $json = json_decode($result->getData(), true);
            if ($json === null) {
                throw new Klarna_Checkout_FormatException;
            }
            $resource->parse($json);
        }

        return $result;
    }

    /**
     * Perform a HTTP Call on the supplied resource using the wanted method.
     *
     * @param string                            $method   HTTP Method
     * @param Klarna_Checkout_ResourceInterface $resource Klarna Order
     * @param array                             $options  Options
     *
     * @throws Klarna_Checkout_Exception if 4xx or 5xx response code.
     * @return Result object containing status code and payload
     */
    protected function handle(
        $method,
        Klarna_Checkout_ResourceInterface $resource,
        array $options = null
    ) {
        // Define the target URL
        $url = $this->getUrl($resource, $options);

        // Set a payload if it is a POST call.
        $payload = '';
        if ($method === 'POST') {
            $payload = json_encode($resource->marshal());
        }

        // Create a HTTP Request object
        $request = $this->createRequest($resource, $method, $payload, $url);
        // $this->_setContent($request, $payload, $method);

        // Execute the HTTP Request
        $result = $this->http->send($request);

        // Check if we got an Error status code back
        $this->verifyResponse($result);

        // Handle statuses appropriately.
        return $this->handleResponse($result, $resource);
    }

}