<?php

/*
 * This file is part of the prooph/event-store package.
 * (c) Alexander Miertsch <contact@prooph.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Prooph\EventStore\Adapter\Zf2;

use Prooph\Common\Messaging\DomainEvent;
use Prooph\EventStore\Adapter\Exception\ConfigurationException;
use Prooph\EventStore\Adapter\Feature\CanHandleTransaction;
use Prooph\EventStore\Exception\RuntimeException;
use Prooph\EventStore\Stream\Stream;
use Prooph\EventStore\Stream\StreamName;
use Zend\Db\Adapter\Adapter as ZendDbAdapter;
use Zend\Db\Sql\Ddl\Column\Integer;
use Zend\Db\Sql\Ddl\Column\Text;
use Zend\Db\Sql\Ddl\Column\Varchar;
use Zend\Db\Sql\Ddl\Constraint\PrimaryKey;
use Zend\Db\Sql\Ddl\CreateTable;
use Zend\Db\Sql\Ddl\DropTable;
use Zend\Db\TableGateway\TableGateway;
use Zend\Db\Adapter\Platform;
use Zend\Serializer\Serializer;

/**
 * EventStore Adapter Zf2EventStoreAdapter
 * 
 * @author Alexander Miertsch <contact@prooph.de>
 */
class Zf2EventStoreAdapter implements \Prooph\EventStore\Adapter\Adapter, CanHandleTransaction
{

    /**
     * @var ZendDbAdapter 
     */
    protected $dbAdapter;

    /**
     *
     * @var TableGateway[] 
     */
    protected $tableGateways;

    /**
     * Custom stream to table mapping
     * 
     * @var array 
     */
    protected $streamTableMap = array();

    /**
     * Serialize adapter used to serialize event payload
     *
     * @var string|\Zend\Serializer\Adapter\AdapterInterface
     */
    protected $serializerAdapter;

    /**
     * @var array
     */
    protected $standardColumns = ['event_id', 'event_name', 'event_class', 'created_at', 'payload', 'version'];

    /**
     * @param array $configuration
     * @throws \Prooph\EventStore\Adapter\Exception\ConfigurationException
     */
    public function __construct(array $configuration)
    {
        if (!isset($configuration['connection']) && !isset($configuration['zend_db_adapter'])) {
            throw new ConfigurationException('DB adapter configuration is missing');
        }

        if (isset($configuration['stream_table_map'])) {
            $this->streamTableMap = $configuration['stream_table_map'];
        }

        $this->dbAdapter = (isset($configuration['zend_db_adapter']))?
            $configuration['zend_db_adapter'] :
            new ZendDbAdapter($configuration['connection']);

        if (isset($configuration['serializer_adapter'])) {
            $this->serializerAdapter = $configuration['serializer_adapter'];
        }
    }

    /**
     * @param Stream $stream
     * @throws \Prooph\EventStore\Exception\RuntimeException
     * @return void
     */
    public function create(Stream $stream)
    {
        if (count($stream->streamEvents()) === 0) {
            throw new RuntimeException(
                sprintf(
                    "Cannot create empty stream %s. %s requires at least one event to extract metadata information",
                    $stream->streamName()->toString(),
                    __CLASS__
                )
            );
        }

        $firstEvent = $stream->streamEvents()[0];

        $this->createSchemaFor($stream->streamName(), $firstEvent->metadata());

        $this->appendTo($stream->streamName(), $stream->streamEvents());
    }

    /**
     * @param StreamName $streamName
     * @param array $streamEvents
     * @throws \Prooph\EventStore\Exception\StreamNotFoundException If stream does not exist
     * @return void
     */
    public function appendTo(StreamName $streamName, array $streamEvents)
    {
        foreach ($streamEvents as $event) {
            $this->insertEvent($streamName, $event);
        }
    }

    /**
     * @param StreamName $streamName
     * @param null|int $minVersion
     * @return Stream|null
     */
    public function load(StreamName $streamName, $minVersion = null)
    {
        $events = $this->loadEventsByMetadataFrom($streamName, array(), $minVersion);

        return new Stream($streamName, $events);
    }

    /**
     * @param StreamName $streamName
     * @param array $metadata
     * @param null|int $minVersion
     * @return DomainEvent[]
     */
    public function loadEventsByMetadataFrom(StreamName $streamName, array $metadata, $minVersion = null)
    {
        $tableGateway = $this->getTablegateway($streamName);

        $sql = $tableGateway->getSql();

        $select = $sql->select()->order('version');

        $where = new \Zend\Db\Sql\Where();

        if (!is_null($minVersion)) {
            $where->AND->greaterThanOrEqualTo('version', $minVersion);
        }

        if (! empty($metadata)) {

            foreach ($metadata as $key => $value) {
                $where->AND->equalTo($key, (string)$value);
            }
        }

        $select->where($where);

        $eventsData = $tableGateway->selectWith($select);

        $events = array();

        foreach ($eventsData as $eventData) {
            $payload = Serializer::unserialize($eventData->payload, $this->serializerAdapter);

            $eventClass = $eventData['event_class'];

            //Add metadata stored in table
            foreach ($eventData as $key => $value) {
                if (! in_array($key, $this->standardColumns)) {
                    $metadata[$key] = $value;
                }
            }

            $events[] = $eventClass::fromArray(
                [
                    'uuid' => $eventData['event_id'],
                    'name' => $eventData['event_name'],
                    'version' => (int)$eventData['version'],
                    'created_at' => $eventData['created_at'],
                    'payload' => $payload,
                    'metadata' => $metadata
                ]
            );
        }

        return $events;
    }

    public function beginTransaction()
    {
        $this->dbAdapter->getDriver()->getConnection()->beginTransaction();
    }

    public function commit()
    {
        $this->dbAdapter->getDriver()->getConnection()->commit();
    }

    public function rollback()
    {
        $this->dbAdapter->getDriver()->getConnection()->rollback();
    }

    /**
     * @param StreamName $streamName
     * @param array $metadata
     * @param bool $returnSql
     * @return string|null Whether $returnSql is true or not function will return generated sql or execute it directly
     */
    public function createSchemaFor(StreamName $streamName, array $metadata = array(), $returnSql = false)
    {
        $createTable = new CreateTable($this->getTable($streamName));

        $createTable->addColumn(new Varchar('event_id', 100))
            ->addColumn(new Integer('version'))
            ->addColumn(new Varchar('event_name', 100))
            ->addColumn(new Varchar('event_class', 100))
            ->addColumn(new Text('payload'))
            ->addColumn(new Varchar('created_at', 50));

        foreach ($metadata as $key => $value) {
            $createTable->addColumn(new Varchar($key, 100));
        }

        $createTable->addConstraint(new PrimaryKey('event_id'));

        if ($returnSql) {
            return $createTable->getSqlString($this->dbAdapter->getPlatform());
        }

        $this->dbAdapter->getDriver()
            ->getConnection()
            ->execute($createTable->getSqlString($this->dbAdapter->getPlatform()));
    }

    /**
     * Drops a stream table
     *
     * Use this function with caution. All your events will be lost! But it can be useful in migration scenarios.
     *
     * @param StreamName $streamName
     * @param bool $returnSql
     * @return string|null Whether $returnSql is true or not function will return generated sql or execute it directly
     */
    public function dropSchemaFor(StreamName $streamName, $returnSql = false)
    {
        $dropTable = new DropTable($this->getTable($streamName));

        if ($returnSql) {
            return $dropTable->getSqlString($this->dbAdapter->getPlatform());
        }

        $this->dbAdapter->getDriver()
            ->getConnection()
            ->execute($dropTable->getSqlString($this->dbAdapter->getPlatform()));
    }

    /**
     * Insert an event
     *
     * @param StreamName $streamName
     * @param DomainEvent $e
     * @return void
     */
    protected function insertEvent(StreamName $streamName, DomainEvent $e)
    {
        $eventData = array(
            'event_id' => $e->uuid()->toString(),
            'version' => $e->version(),
            'event_name' => $e->messageName(),
            'event_class' => get_class($e),
            'payload' => Serializer::serialize($e->payload(), $this->serializerAdapter),
            'created_at' => $e->createdAt()->format(\DateTime::ISO8601)
        );

        foreach ($e->metadata() as $key => $value) {
            $eventData[$key] = (string)$value;
        }

        $tableGateway = $this->getTablegateway($streamName);

        $tableGateway->insert($eventData);
    }

    /**
     * Get the corresponding Tablegateway of the given stream name
     *
     * @param StreamName $streamName
     *
     * @return TableGateway
     */
    protected function getTablegateway(StreamName $streamName)
    {
        if (!isset($this->tableGateways[$streamName->toString()])) {
            $this->tableGateways[$streamName->toString()] = new TableGateway($this->getTable($streamName), $this->dbAdapter);
        }

        return $this->tableGateways[$streamName->toString()];
    }

    /**
     * Get table name for given stream name
     *
     * @param StreamName $streamName
     * @return string
     */
    protected function getTable(StreamName $streamName)
    {
        if (isset($this->streamTableMap[$streamName->toString()])) {
            $tableName = $this->streamTableMap[$streamName->toString()];
        } else {
            $tableName = strtolower($this->getShortStreamName($streamName));

            if (strpos($tableName, "_stream") === false) {
                $tableName.= "_stream";
            }
        }

        return $tableName;
    }

    /**
     * @param StreamName $streamName
     * @return string
     */
    protected function getShortStreamName(StreamName $streamName)
    {
        $streamName = str_replace('-', '_', $streamName->toString());
        return join('', array_slice(explode('\\', $streamName), -1));
    }
}
