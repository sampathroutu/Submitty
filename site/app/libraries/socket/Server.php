<?php

declare(strict_types=1);

namespace app\libraries\socket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use app\libraries\Core;
use app\libraries\TokenManager;

class Server implements MessageComponentInterface {

    // Holds the connections object array, used directly by the class functions
    private $clients;

    // Holds the mapping between Connection Objects (key) and User_ID (value)
    private $sessions;

    // Holds the mapping between User_ID (key) and Connection object (value)
    /** @var array<string, \Ratchet\ConnectionInterface> */
    private $users;

    /** @var Core */
    private $core;

    public function __construct(Core $core) {
        $this->clients = new \SplObjectStorage();
        $this->sessions = [];

        $this->core = $core;
    }

    /**
     * This function checks if a given connection object is authenticated
     * It uses the submitty_session cookie in the header data to work
     */
    private function checkAuth(ConnectionInterface $conn): bool {
        $request = $conn->httpRequest;
        $client_id = $conn->resourceId;
        $userAgent = $request->getHeader('user-agent');

        if ($userAgent[0] === "websocket-client-php") {
            return true;
        }
        else {
            $cookieString = $request->getHeader("cookie");
            parse_str(strtr($cookieString[0], array('&' => '%26', '+' => '%2B', ';' => '&')), $cookies);

            $sessid = $cookies['submitty_session'];

            try {
                $token = TokenManager::parseSessionToken(
                    $sessid,
                    $this->core->getConfig()->getBaseUrl(),
                    $this->core->getConfig()->getSecretSession()
                );
                $session_id = $token->getClaim('session_id');
                $user_id = $token->getClaim('sub');
                $logged_in = $this->core->getSession($session_id, $user_id);
                if (!$logged_in) {
                    $conn->send('{"sys": "Unauthenticated User"}');
                    $conn->close();
                    return false;
                }
                else {
                    $this->setSocketClient($user_id, $conn);
                    $conn->send('{"sys": "Connected"}');
                    return true;
                }
            }
            catch (\InvalidArgumentException $exc) {
                die($exc);
            }
        }
    }

    /**
     * Push a given message to all or all-but-sender connections
     * @param $from
     * @param $content
     * @param bool $all, true to send to all, false to send to all but $from
     */
    private function broadcast(ConnectionInterface $from, string $content, bool $all = true): void {
        if ($all) {
            foreach ($this->clients as $client) {
                $client->send($content);
            }
        }
        else {
            foreach ($this->clients as $client) {
                if ($client !== $from) {
                    $client->send($content);
                }
            }
        }
    }

    /** Note: Even if some of the below functions are not currently used,
     * Do not remove them. Use them in the future for features like -
     *      1. pushing notifications to a set of users
     *      2. User to User (s) chat/communication
     */

    /**
     * Connection object is the object stored against $this->clients for a given connection
     * Connection object contains all details of the connection and can be used to push messages to client
     *
     * User_ID refers to the user_id of the user who is making a connection to the socket from a web page
     * For example: if instructor has opened a socket connection from Forum threads page, user_id is instructor
     */

    /**
     * Fetches Connection object of a given User_ID
     * @param $user_id
     * @return bool|\Ratchet\ConnectionInterface
     */
    private function getSocketClientID(string $user_id) {
        if (isset($this->users[$user_id])) {
            return $this->users[$user_id];
        }
        else {
            return false;
        }
    }

    /**
     * Fetches User_ID of a given socket Connection object
     * @param $conn
     * @return integer
     */
    private function getSocketUserID(ConnectionInterface $conn): void {
        if (isset($this->sessions[$conn->resourceId])) {
            return $this->sessions[$conn->resourceId];
        }
        else {
            return false;
        }
    }

    /**
     * Sets Connection object associativity with User_ID
     * @param $user_id
     * @param $conn
     * @return void
     */
    private function setSocketClient(string $user_id, ConnectionInterface $conn): void {
        $this->sessions[$conn->resourceId] = $user_id;
        $this->users[$user_id] = $conn;
    }

    /**
     * Deletes Connection object associativity with User_ID
     * @param $conn
     * @return void
     */
    private function removeSocketClient(ConnectionInterface $conn): void {
        $user_id = $this->getSocketUserID($conn);
        unset($this->sessions[$conn->resourceId]);
        unset($this->users[$user_id]);
    }

    /**
     * When a new user connects to the socket, check authentication
     * @param ConnectionInterface $conn
     */
    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);

        $this->checkAuth($conn);
    }

    /**
     * Function to run whenever the socket receives a new message from any client
     * @param ConnectionInterface $from
     * @param string $msgString
     */
    public function onMessage(ConnectionInterface $from, string $msgString): void {
        if ($msgString === 'ping') {
            $this->broadcast($from, 'pong');
            return;
        }

        if ($this->checkAuth($from)) {
            $msg = json_decode($msgString, true);

            switch ($msg["type"]) {
                case "new_thread":
                case "new_post":
                    $user_id = $msg["data"]["user_id"];
                    if ($fromConn = $this->getSocketClientID($user_id)) {
                        $this->broadcast($fromConn, $msgString, true);
                    }
                    else {
                        $this->broadcast($from, $msgString, true);
                    }
                    break;
                default:
                    $this->broadcast($from, $msgString, true);
                    break;
            }
        }
    }

    /**
     * When any client closes the connection, remove information about them
     * @param ConnectionInterface $conn
     */
    public function onClose(ConnectionInterface $conn): void {
        $this->removeSocketClient($conn);
        $this->clients->detach($conn);
        $conn->send('{"sys": "Disconnected"}');
    }

    /**
     * When any error occurs within the socket server script
     * @param ConnectionInterface $conn
     * @param \Exception $e
     */
    public function onError(ConnectionInterface $conn, \Exception $e): void {
        $conn->close();
    }
}
