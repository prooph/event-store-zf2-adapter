event-store-zf2-adapter
=======================

*This package is abandoned and no longer maintained. we suggest using the [prooph/event-store-doctrine-adapter](https://github.com/prooph/event-store-doctrine-adapter) instead.*

[![Build Status](https://travis-ci.org/prooph/event-store-zf2-adapter.svg?branch=master)](https://travis-ci.org/prooph/event-store-zf2-adapter)
[![Coverage Status](https://coveralls.io/repos/prooph/event-store-zf2-adapter/badge.png)](https://coveralls.io/r/prooph/event-store-zf2-adapter)

Use [ProophEventStore](https://github.com/prooph/event-store) with [ZF2 DB](https://github.com/zendframework/zf2/tree/master/library/Zend/Db).

Database Set Up
---------------

The database structure depends on the [stream strategies](https://github.com/prooph/event-store#streamstrategies) you want to use for your aggregate roots.
You can find example SQLs for MySql in the [scripts folder](https://github.com/prooph/event-store-zf2-adapter/blob/master/scripts/)
and an [example script](https://github.com/prooph/event-store-zf2-adapter/blob/master/examples/create-schema.php) of how you can use the adapter to generate stream tables.



License
-------

Released under the [New BSD License](https://github.com/prooph/event-store-zf2-adapter/blob/master/LICENSE).
