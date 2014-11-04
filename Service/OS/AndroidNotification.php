<?php

namespace RMS\PushNotificationsBundle\Service\OS;

use RMS\PushNotificationsBundle\Exception\InvalidMessageTypeException,
    RMS\PushNotificationsBundle\Message\AndroidMessage,
    RMS\PushNotificationsBundle\Message\MessageInterface;
use Buzz\Browser;

class AndroidNotification implements OSNotificationServiceInterface
{
    /**
     * Username for auth
     *
     * @var string
     */
    protected $username;

    /**
     * Password for auth
     *
     * @var string
     */
    protected $password;

    /**
     * The source of the notification
     * eg com.example.myapp
     *
     * @var string
     */
    protected $source;

    /**
     * Authentication token
     *
     * @var string
     */
    protected $authToken;

    /**
     * Constructor
     *
     */
    public function __construct()
    {
        $this->authToken = "";
    }

    /**
     * Sends a C2DM message
     * This assumes that a valid auth token can be obtained
     *
     * @param  \RMS\PushNotificationsBundle\Message\MessageInterface              $message
     * @throws \RMS\PushNotificationsBundle\Exception\InvalidMessageTypeException
     * @return bool
     */
    public function send(MessageInterface $message)
    {
        if (!$message instanceof AndroidMessage) {
            throw new InvalidMessageTypeException(sprintf("Message type '%s' not supported by C2DM", get_class($message)));
        }

        if ($this->getAuthToken()) {
            $headers[] = "Authorization: GoogleLogin auth=" . $this->authToken;
            $data = $message->getMessageBody();

            $buzz = new Browser();
            $buzz->getClient()->setVerifyPeer(false);
            $response = $buzz->post("https://android.apis.google.com/c2dm/send", $headers, http_build_query($data));

            return preg_match("/^id=/", $response->getContent()) > 0;
        }

        return false;
    }

    /**
     * Gets a valid authentication token
     *
     * @return bool
     */
    protected function getAuthToken()
    {
        $data = array(
            "Email"         => $this->username,
            "Passwd"        => $this->password,
            "accountType"   => "HOSTED_OR_GOOGLE",
            "source"        => $this->source,
            "service"       => "ac2dm"
        );

        $buzz = new Browser();
        $buzz->getClient()->setVerifyPeer(false);
        $response = $buzz->post("https://www.google.com/accounts/ClientLogin", array(), http_build_query($data));
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        preg_match("/Auth=([a-z0-9_\-]+)/i", $response->getContent(), $matches);
        $this->authToken = $matches[1];

        return true;
    }

    /**
     * @param string $password
     */
    public function setPassword($password)
    {
        $this->password = $password;
    }

    /**
     * @param string $source
     */
    public function setSource($source)
    {
        $this->source = $source;
    }

    /**
     * @param string $username
     */
    public function setUsername($username)
    {
        $this->username = $username;
    }

    /**
     * Configure the notifications service
     *
     * @param $configurationArray
     * @return mixed
     */
    public function configure($configurationArray)
    {
        $this->setPassword($configurationArray['password']);
        $this->setSource($configurationArray['source']);
        $this->setUsername($configurationArray['username']);
    }
}
