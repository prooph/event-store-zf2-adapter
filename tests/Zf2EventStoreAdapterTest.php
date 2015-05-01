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

use Prooph\EventStore\Stream\DomainEventMetadataWriter;
use Prooph\EventStore\Stream\Stream;
use Prooph\EventStore\Stream\StreamName;
use Prooph\EventStoreTest\Mock\UserCreated;
use Prooph\EventStoreTest\Mock\UsernameChanged;
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

        $this->adapter->beginTransaction();

        $this->adapter->create($testStream);

        $this->adapter->commit();

        $streamEvents = $this->adapter->loadEventsByMetadataFrom(new StreamName('Prooph\Model\User'), array('tag' => 'person'));

        $this->assertEquals(1, count($streamEvents));

        $this->assertEquals($testStream->streamEvents()[0]->uuid()->toString(), $streamEvents[0]->uuid()->toString());
        $this->assertEquals($testStream->streamEvents()[0]->createdAt()->format('Y-m-d\TH:i:s.uO'), $streamEvents[0]->createdAt()->format('Y-m-d\TH:i:s.uO'));
        $this->assertEquals('Prooph\EventStoreTest\Mock\UserCreated', $streamEvents[0]->messageName());
        $this->assertEquals('contact@prooph.de', $streamEvents[0]->payload()['email']);
        $this->assertEquals(1, $streamEvents[0]->version());
    }

    /**
     * @test
     */
    public function it_appends_events_to_a_stream()
    {
        $this->adapter->create($this->getTestStream());

        $streamEvent = UsernameChanged::with(
            array('name' => 'John Doe'),
            2
        );

        DomainEventMetadataWriter::setMetadataKey($streamEvent, 'tag', 'person');

        $this->adapter->appendTo(new StreamName('Prooph\Model\User'), array($streamEvent));

        $stream = $this->adapter->load(new StreamName('Prooph\Model\User'));

        $this->assertEquals('Prooph\Model\User', $stream->streamName()->toString());
        $this->assertEquals(2, count($stream->streamEvents()));
    }

    /**
     * @test
     */
    public function it_loads_events_from_min_version_on()
    {
        $this->adapter->create($this->getTestStream());

        $streamEvent1 = UsernameChanged::with(
            array('name' => 'John Doe'),
            2
        );

        DomainEventMetadataWriter::setMetadataKey($streamEvent1, 'tag', 'person');

        $streamEvent2 = UsernameChanged::with(
            array('name' => 'Jane Doe'),
            2
        );

        DomainEventMetadataWriter::setMetadataKey($streamEvent2, 'tag', 'person');

        $this->adapter->appendTo(new StreamName('Prooph\Model\User'), array($streamEvent1, $streamEvent2));

        $stream = $this->adapter->load(new StreamName('Prooph\Model\User'), 2);

        $this->assertEquals('Prooph\Model\User', $stream->streamName()->toString());
        $this->assertEquals(2, count($stream->streamEvents()));
        $this->assertEquals('John Doe', $stream->streamEvents()[0]->payload()['name']);
        $this->assertEquals('Jane Doe', $stream->streamEvents()[1]->payload()['name']);
    }

    /**
     * @return Stream
     */
    private function getTestStream()
    {
        $streamEvent = UserCreated::with(
            array('name' => 'Max Mustermann', 'email' => 'contact@prooph.de'),
            1
        );

        DomainEventMetadataWriter::setMetadataKey($streamEvent, 'tag', 'person');

        return new Stream(new StreamName('Prooph\Model\User'), array($streamEvent));
    }
}
 