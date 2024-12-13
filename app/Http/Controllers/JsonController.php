<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;

class JsonController extends Controller
{
    private $_result = false;
    private $_messages = array();

    /**
     * Generate the auth for a request
     *
     * @param mixed[] ...$params Parameters for "Auth Field" section of the digest
     *
     * @return array
     */
    protected function generateAuth(...$params)
    {
        $now = Carbon::now('Europe/London');
        $timeStamp = $now->toAtomString();
        if(empty($params)) {
            $baseKey = sha1(
                sprintf(
                    '%s|%s',
                    env('SSP_SCID'),
                    $now->format('d/m/Y H:i:s')
                )
            );
        }
        else {
            $params = implode('|', $params);
            $baseKey = sha1(
                sprintf(
                    '%s|%s|%s',
                    env('SSP_SCID'),
                    $now->format('d/m/Y H:i:s'),
                    $params
                )
            );
        }

        $digest = sha1(
            sprintf(
                '%s|%s',
                $baseKey,
                env('SSP_PASSWORD')
            )
        );

        return [
            'BrokerId' => env('SSP_SCID'),
            'Digest' => $digest,
            'Timestamp' => $timeStamp
        ];
    }

    /**
     * Set the result for this API call
     *
     * @param mixed $result The result for the API call
     *
     * @return void
     */
    protected function setResult($result)
    {
        $this->_result = $result;
    }

    /**
     * Add a message to the API response
     *
     * @param string  $type   The type of message, error, success etc
     * @param string  $format The base format for the message content
     * @param mixed[] $args   Any parameters for the message
     *
     * @return string
     */
    protected function addMessage($type, $format, $args)
    {
        $message = vsprintf($format, $args);
        if ($message === false) {
            $this->_messages[] = ['type' => $type, 'text' => $format];
            return $format;
        } else {
            $this->_messages[] = ['type' => $type, 'text' => $message];
            return $message;
        }
    }

    /**
     * Add an error to the response
     *
     * @param string  $format  The base format for the message content
     * @param mixed[] ...$args Any parameters for the message
     *
     * @return void
     */
    protected function addValidationError()
    {
        $args = func_get_args();
        $attribute = array_shift($args);
        $format = array_shift($args);

        $message = vsprintf($format, $args);
        if ($message === false) {
            $this->_messages[] = ['type' => 'error', 'text' => $format, 'attribute' => $attribute];
        } else {
            $this->_messages[] = ['type' => 'error', 'text' => $message, 'attribute' => $attribute];
        }
    }

    /**
     * Add an error to the response
     *
     * @param string  $format  The base format for the message content
     * @param mixed[] ...$args Any parameters for the message
     *
     * @return void
     */
    protected function addError()
    {
        $args = func_get_args();
        $format = array_shift($args);
        $this->addMessage('error', $format, $args);
    }

    /**
     * Add an error to the response
     *
     * @param string  $format  The base format for the message content
     * @param mixed[] ...$args Any parameters for the message
     *
     * @return void
     */
    protected function logError()
    {
        $args = func_get_args();
        $format = array_shift($args);
        $message = $this->addMessage('error', $format, $args);

        Log::error(
            $message,
            ['trace' => debug_backtrace()]
        );
    }

    /**
     * Add a success to the response
     *
     * @param string  $format  The base format for the message content
     * @param mixed[] ...$args Any parameters for the message
     *
     * @return void
     */
    protected function addSuccess()
    {
        $args = func_get_args();
        $format = array_shift($args);
        $this->addMessage('success', $format, $args);
    }

    /**
     * Add a warning to the response
     *
     * @param string  $format  The base format for the message content
     * @param mixed[] ...$args Any parameters for the message
     *
     * @return void
     */
    protected function addWarning()
    {
        $args = func_get_args();
        $format = array_shift($args);
        $this->addMessage('warning', $format, $args);
    }

    /**
     * Add a info to the response
     *
     * @param string  $format  The base format for the message content
     * @param mixed[] ...$args Any parameters for the message
     *
     * @return void
     */
    protected function addInfo()
    {
        $args = func_get_args();
        $format = array_shift($args);
        $this->addMessage('info', $format, $args);
    }

    /**
     * Checks to see if the response contains a message
     *
     * @param string $type Optionally specify a specific type
     *
     * @return bool
     */
    protected function hasMessage($type = '')
    {
        if (empty($type)) {
            return !empty($this->_messages);
        } else {
            foreach ($this->_messages as $message) {
                if ($message['type'] == $type) {
                    return true;
                }
            }
            return false;
        }
    }

    /**
     * Get the response for the API call
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function getResponse()
    {
        return response()->json(
            [
                'result' => $this->_result,
                'messages' => $this->_messages
            ]
        );
    }

    /**
     * Merge a JSON response from a different function into this
     *
     * @param \Illuminate\Http\JsonResponse $response The new response to merge
     *
     * @return mixed
     */
    protected function mergeResponse($response)
    {
        $data = $response->getData(true);
        $this->_messages = array_merge($data['messages'], $this->_messages);
        return $data['result'];
    }
}
