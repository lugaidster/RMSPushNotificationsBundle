<?php

namespace RMS\PushNotificationsBundle\Service\OS;

use RMS\PushNotificationsBundle\Exception\InvalidMessageTypeException,
    RMS\PushNotificationsBundle\Message\AppleMessage,
    RMS\PushNotificationsBundle\Message\MessageInterface;

class AppleNotification implements OSNotificationServiceInterface
{
    /**
     * Whether or not to use the sandbox APNS
     *
     * @var bool
     */
    protected $useSandbox = false;

    /**
     * Path to PEM file
     *
     * @var string
     */
    protected $pem;

    /**
     * Passphrase for PEM file
     *
     * @var string
     */
    protected $passphrase;

    /**
     * Array for streams to APN
     *
     * @var array
     */
    protected $apnStreams;

    /**
     * Array for messages to APN
     *
     * @var array
     */
    protected $messages;

    /**
     * Last used message ID
     *
     * @var int
     */
    protected $lastMessageId;

    /**
     * JSON_UNESCAPED_UNICODE
     *
     * @var boolean
     */
    protected $jsonUnescapedUnicode = FALSE;

    /**
     * Collection of the responses from the APN
     *
     * @var array
     */
    protected $responses = array();

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->apnStreams = array();
        $this->messages = array();
        $this->lastMessageId = -1;
    }

    /**
     * Set option JSON_UNESCAPED_UNICODE to json encoders
     *
     * @param boolean $jsonUnescapedUnicode
     *
     * @return $this
     */
    public function setJsonUnescapedUnicode($jsonUnescapedUnicode)
    {
        $this->jsonUnescapedUnicode = (bool) $jsonUnescapedUnicode;

        return $this;
    }

    /**
     * Send a notification message
     *
     * @param  \RMS\PushNotificationsBundle\Message\MessageInterface|\RMS\PushNotificationsBundle\Service\OS\MessageInterface $message
     *
     * @throws \RuntimeException
     * @throws \RMS\PushNotificationsBundle\Exception\InvalidMessageTypeException
     *
     * @return bool
     */
    public function send(MessageInterface $message)
    {
        if (!$message instanceof AppleMessage) {
            throw new InvalidMessageTypeException(sprintf("Message type '%s' not supported by APN", get_class($message)));
        }

        $apnURL = "tls://gateway.push.apple.com:2195";
        if ($this->useSandbox) {
            $apnURL = "tls://gateway.sandbox.push.apple.com:2195";
        }

        $messageId = ++$this->lastMessageId;
        $this->messages[$messageId] = $this->createPayload($messageId, $message->getExpiry(), $message->getDeviceIdentifier(), $message->getMessageBody());
        $errors = $this->sendMessages($messageId, $apnURL);

        return !$errors;
    }

    /**
     * Send all notification messages starting from the given ID
     *
     * @param  int                                                                $firstMessageId
     * @param  string                                                             $apnURL
     * @throws \RuntimeException
     * @throws \RMS\PushNotificationsBundle\Exception\InvalidMessageTypeException
     * @return int
     */
    protected function sendMessages($firstMessageId, $apnURL)
    {
        $errors = array();
        // Loop through all messages starting from the given ID
        for ($currentMessageId = $firstMessageId; $currentMessageId < count($this->messages); $currentMessageId++) {
            // Send the message
            $result = $this->writeApnStream($apnURL, $this->messages[$currentMessageId]);

            $errors = array();

            // Check if there is an error result
            if (is_array($result)) {
                $this->responses[] = $result;
                // Resend all messages that were sent after the failed message
                $this->sendMessages($result['identifier']+1, $apnURL);
                $errors[] = $result;
            } else {
                $this->responses[] = true;
            }
        }

        return $errors;
    }

    /**
     * Write data to the apn stream that is associated with the given apn URL
     *
     * @param  string            $apnURL
     * @param  string            $payload
     * @throws \RuntimeException
     * @return mixed
     */
    protected function writeApnStream($apnURL, $payload)
    {
        // Get the correct Apn stream and send data
        $fp = $this->getApnStream($apnURL);
        $response = (strlen($payload) === @fwrite($fp, $payload, strlen($payload)));

        // Check if there is responsedata to read
        $readStreams = array($fp);
        $null = NULL;
        $streamsReadyToRead = @stream_select($readStreams, $null, $null, 0, 0);
        if ($streamsReadyToRead > 0) {
            // Unpack error response data and set as the result
            $response = @unpack("Ccommand/Cstatus/Nidentifier", fread($fp, 6));
            $this->closeApnStream($apnURL);
        }

        // Will contain true if writing succeeded and no error is returned yet
        return $response;
    }

    /**
     * Get an apn stream associated with the given apn URL, create one if necessary
     *
     * @param  string            $apnURL
     * @throws \RuntimeException
     * @return resource
     */
    protected function getApnStream($apnURL)
    {
        if (!isset($this->apnStreams[$apnURL])) {
            // No stream found, setup a new stream
            $ctx = $this->getStreamContext();
            $this->apnStreams[$apnURL] = stream_socket_client($apnURL, $err, $errstr, 60, STREAM_CLIENT_CONNECT, $ctx);

            if (!$this->apnStreams[$apnURL]) {
                throw new \RuntimeException("Couldn't connect to APN server. Error no $err: $errstr");
            }

            // Reduce buffering and blocking
            if (function_exists("stream_set_read_buffer")) {
                stream_set_read_buffer($this->apnStreams[$apnURL], 6);
            }
            stream_set_write_buffer($this->apnStreams[$apnURL], 0);
            stream_set_blocking($this->apnStreams[$apnURL], 0);
        }

        return $this->apnStreams[$apnURL];
    }

    /**
     * Close the apn stream associated with the given apn URL
     *
     * @param string $apnURL
     */
    protected function closeApnStream($apnURL)
    {
        if (isset($this->apnStreams[$apnURL])) {
            // Stream found, close the stream
            fclose($this->apnStreams[$apnURL]);
            unset($this->apnStreams[$apnURL]);
        }
    }

    /**
     * Gets a stream context set up for SSL
     * using our PEM file and passphrase
     *
     * @return resource
     */
    protected function getStreamContext()
    {
        $ctx = stream_context_create();

        stream_context_set_option($ctx, "ssl", "local_cert", $this->pem);
        if (strlen($this->passphrase)) {
            stream_context_set_option($ctx, "ssl", "passphrase", $this->passphrase);
        }

        return $ctx;
    }

    /**
     * Creates the full payload for the notification
     *
     * @param int    $messageId
     * @param string $expiry
     * @param string $token
     * @param array  $message
     *
     * @return string
     *
     * @throws \LogicException
     * @throws \InvalidArgumentException
     */
    protected function createPayload($messageId, $expiry, $token, $message)
    {
        if ($this->jsonUnescapedUnicode) {
            // WARNING:
            // Set option JSON_UNESCAPED_UNICODE is violation of RFC 4627
            // Because required validate charsets (Must be UTF-8)

            $encoding = mb_detect_encoding($message['aps']['alert']);
            if ($encoding != 'UTF-8' && $encoding != 'ASCII') {
                throw new \InvalidArgumentException(sprintf(
                    'Message must be UTF-8 encoding, "%s" given.',
                    mb_detect_encoding($message['aps']['alert'])
                ));
            }

            $jsonBody = json_encode($message, JSON_UNESCAPED_UNICODE);
        } else {
            $jsonBody = json_encode($message);
        }

        $token = preg_replace("/[^0-9A-Fa-f]/", "", $token);
        $payload = chr(1) . pack("N", $messageId) . pack("N", $expiry) . pack("n", 32) . pack("H*", $token) . pack("n", strlen($jsonBody)) . $jsonBody;

        return $payload;
    }

    /**
     * Returns responses
     *
     * @return array
     */
    public function getResponses()
    {
        return $this->responses;
    }

    /**
     * @param string $pem
     */
    public function setPem($pem)
    {
        $this->pem = $pem;
    }

    /**
     * @param string $passphrase
     */
    public function setPassphrase($passphrase)
    {
        $this->passphrase = $passphrase;
    }

    /**
     * @param boolean $useSandbox
     */
    public function setUseSandbox($useSandbox)
    {
        $this->useSandbox = $useSandbox;
    }

    /**
     * Configure the notifications service
     *
     * @param $configurationArray
     * @return mixed
     */
    public function configure($configurationArray)
    {
        foreach ($this->apnStreams as $stream) {
            if (null !== $stream) {
                fclose($stream);
            }
        }

        $this->apnStreams = array();
        $this->messages = array();
        $this->lastMessageId = -1;

        $this->setJsonUnescapedUnicode($configurationArray['jsonUnescapedUnicode']);
        $this->setPassphrase($configurationArray['passphrase']);
        $this->setPem($configurationArray['pem']);
        $this->setUseSandbox($configurationArray['useSandbox']);
    }
}
