<?php

namespace limitium\zmq\test;

use limitium\zmq\Publisher;
use limitium\zmq\Subscriber;
use limitium\zmq\Zmsg;
use PHPUnit_Framework_TestCase;

class SubscriberTest extends PHPUnit_Framework_TestCase
{
    public function testReceive()
    {
        $context = new \ZMQContext();

        $endpoint = "inproc://zmq_receiver";
        $subscriber1 = new Subscriber($endpoint, $context, 111);
        $subscriber2 = new Subscriber($endpoint, $context, 111);

        $pubMsg = $this->createPublisher($context, $endpoint);

        $msgOut = "qweqwe";
        $pubMsg->push($msgOut);
        $pubMsg->wrap(sprintf("%.0f", microtime(1) * 1000));
        $pubMsg->send(true);

        $subscriber1->setListener(function ($msg, $time) use ($msgOut) {
            $this->assertTrue($msg == $msgOut);

        })
            ->receiveMessage();

        $subscriber2->setListener(function ($msg, $time) use ($msgOut) {
            $this->assertTrue($msg == $msgOut);

        })
            ->receiveMessage();
    }

    /**
     * @param $context
     * @param $endpoint
     * @return Zmsg
     */
    private function createPublisher($context, $endpoint)
    {
        $publisher = new \ZMQSocket($context, \ZMQ::SOCKET_PUB);
        $publisher->setSockOpt(\ZMQ::SOCKOPT_SNDHWM, 1);
        $publisher->setSockOpt(\ZMQ::SOCKOPT_LINGER, 0);
        $publisher->bind($endpoint);
        return new Zmsg($publisher);
    }
}