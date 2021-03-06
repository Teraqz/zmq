<?php

namespace limitium\zmq;

/**
 * Receives messages from publishers manage them and sends back
 *
 * Class Worker
 * @package limitium\zmq
 */
class Worker extends PollBroker
{
    private $heartbeatAt;
    private $heartbeatDelay;
    private $reconnectDelay;
    private $heartbeatTriesLeft;
    private $heartbeatMaxFails = 4;
    /**
     * @var callable
     */
    private $executor;

    public function __construct($endpoint, $heartbeatDelay = 2500, $reconnectDelay = 5000, \ZMQContext $context = null, $verbose)
    {
        parent::__construct($endpoint, $heartbeatDelay, $context, $verbose);

        $this->heartbeatDelay = $heartbeatDelay;
        $this->reconnectDelay = $reconnectDelay;

        $this->connect();
    }

    /**
     * Sets executor for worker
     * @param callable $executor
     * @return $this
     */
    public function setExecutor(callable $executor)
    {
        $this->executor = $executor;
        return $this;
    }

    /**
     * Start work
     * @throws \Exception
     */
    public function work()
    {
        if (!$this->executor) {
            throw new \Exception("Empty executor");
        }
        $this->poll();
    }

    private function connect()
    {
        if ($this->socket) {
            $this->poll->remove($this->socket);
            unset($this->socket);
        }

        $this->socket = $this->context->getSocket(\ZMQ::SOCKET_DEALER);
        $this->socket->setSockOpt(\ZMQ::SOCKOPT_LINGER, 0);
        $this->socket->connect($this->endpoint);
        $this->poll->add($this->socket, \ZMQ::POLL_IN);

        if ($this->verbose) {
            printf("I: connecting to broker at %s... %s", $this->endpoint, PHP_EOL);
        }
        $this->sendCommand(Commands::W_READY);
        $this->heartbeatTriesLeft = $this->heartbeatMaxFails;
        $this->heartbeatAt = microtime(true) + ($this->heartbeatDelay / 1000);
    }

    private function sendCommand($command, $msg = null)
    {
        if (!$msg) {
            $msg = new Zmsg();
        }
        $msg->push($command);
        $msg->push(Commands::W_WORKER);
        $msg->push("");
        if ($this->verbose) {
            printf("I: sending `%s` to broker %s", $command, PHP_EOL);
            echo $msg->__toString(), PHP_EOL;
        }
        $msg->set_socket($this->socket)->send();
    }

    private function sendHeartbeat($sendHeartBeat)
    {
        if (microtime(true) > $this->heartbeatAt) {
            if ($sendHeartBeat) {
                $this->sendCommand(Commands::W_HEARTBEAT);
            }
            $this->heartbeatAt = microtime(true) + ($this->heartbeatDelay / 1000);
        }
    }

    private function send($data)
    {
        $zmsg = new Zmsg();
        $zmsg->body_set($data);
        //@todo: wrap address;
        $this->sendCommand(Commands::W_RESPONSE, $zmsg);
    }

    protected function onPoll($events, $read, $write)
    {
        $events = $this->poll->poll($read, $write, $this->heartbeatDelay);
        $sendHeartBeat = true;
        if ($events) {
            $zmsg = new Zmsg($this->socket);
            $zmsg->recv();
            if ($this->verbose) {
                echo "I: received message from broker:", PHP_EOL;
                echo $zmsg->__toString(), PHP_EOL;
            }
            $this->heartbeatTriesLeft = $this->heartbeatMaxFails;

            $zmsg->pop();
            $header = $zmsg->pop();
            assert($header == Commands::W_WORKER);

            $command = $zmsg->pop();
            if ($command == Commands::W_HEARTBEAT) {

            } elseif ($command == Commands::W_REQUEST) {
                //@todo: get address
                $result = call_user_func($this->executor, $zmsg->pop());
                $this->send($result);
                //resp = HB
                $sendHeartBeat = false;
            } elseif ($command == Commands::W_RESPONSE) {
                $this->connect();
            } else {
                echo "I: Unsupported command `$command`.", PHP_EOL;
                echo $zmsg->__toString(), PHP_EOL, PHP_EOL;
            }
        } elseif (--$this->heartbeatTriesLeft == 0) {
            if ($this->verbose) {
                echo "I: disconnected from broker - retrying... ", PHP_EOL;
            }
            usleep($this->reconnectDelay * 1000);
            $this->connect();
        }

        $this->sendHeartbeat($sendHeartBeat);
    }
}