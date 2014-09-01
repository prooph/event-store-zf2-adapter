<?php
/*
 * This file is part of the codeliner/event-store-zf2-adapter.
 * (c) Alexander Miertsch <kontakt@codeliner.ws>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 * 
 * Date: 01.09.14 - 02:00
 */

namespace Prooph\EventStoreTest\Adapter\Zf2;

use Prooph\EventStore\Adapter\Zf2\Zf2EventStoreAdapter;

use Prooph\EventStore\Stream\EventId;
use Prooph\EventStore\Stream\EventName;
use Prooph\EventStore\Stream\Stream;
use Prooph\EventStore\Stream\StreamEvent;
use Prooph\EventStore\Stream\StreamName;
use Prooph\EventStoreTest\TestCase;

/**
 * Class Zf2EventStoreAdapterTest
 *
 * @package Prooph\EventStoreTest\Adapter\Zf2
 * @author Alexander Miertsch <kontakt@codeliner.ws>
 */
class Zf2EventStoreAdapterTest extends TestCase
{
    /**
     * @var Zf2EventStoreAdapter
     */
    private $adapter;

    protected function setUp()
    {
        $this->adapter = new Zf2EventStoreAdapter(array(
            'connection' => array(
                'driver' => 'Pdo_Sqlite',
                'database' => ':memory:'
            )
        ));
    }

    /**
     * @test
     */
    public function it_creates_a_stream()
    {
        $testStream = $this->getTestStream();

        $this->adapter->create($testStream);

        $streamEvents = $this->adapter->loadEventsByMetadataFrom(new StreamName('Prooph\Model\User'), array('tag' => 'person'));

        $this->assertEquals(1, count($streamEvents));

        $this->assertEquals($testStream->streamEvents()[0]->eventId()->toString(), $streamEvents[0]->eventId()->toString());
        $this->assertEquals($testStream->streamEvents()[0]->occurredOn()->format('Y-m-d\TH:i:s.uO'), $streamEvents[0]->occurredOn()->format('Y-m-d\TH:i:s.uO'));
        $this->assertEquals('UserCreated', $streamEvents[0]->eventName()->toString());
        $this->assertEquals('contact@prooph.de', $streamEvents[0]->payload()['email']);
        $this->assertEquals(1, $streamEvents[0]->version());
    }

    /**
     * @test
     */
    public function it_appends_events_to_a_stream()
    {
        $this->adapter->create($this->getTestStream());

        $streamEvent = new StreamEvent(
            EventId::generate(),
            new EventName('UsernameChanged'),
            array('name' => 'John Doe'),
            1,
            new \DateTime(),
            array('tag' => 'person')
        );

        $this->adapter->appendTo(new StreamName('Prooph\Model\User'), array($streamEvent));

        $stream = $this->adapter->load(new StreamName('Prooph\Model\User'));

        $this->assertEquals('Prooph\Model\User', $stream->streamName()->toString());
        $this->assertEquals(2, count($stream->streamEvents()));
    }

    /**
     * @return Stream
     */
    private function getTestStream()
    {
        $streamEvent = new StreamEvent(
            EventId::generate(),
            new EventName('UserCreated'),
            array('name' => 'Alex', 'email' => 'contact@prooph.de'),
            1,
            new \DateTime(),
            array('tag' => 'person')
        );

        return new Stream(new StreamName('Prooph\Model\User'), array($streamEvent));
    }
}
 