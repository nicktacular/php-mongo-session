php-mongo-session
=================

A PHP session handler with a Mongo DB backend.

Requirements
============

You'll need:
* PHP version 5.2.17+
* PHP MongoDB driver version 1.3+.
* A sense of adventure.

Quickstart
==========

Pretty simple, actually. First, include `MongoSession` class like so:

```php
require_once 'MongoSession.php';
```

Then you need to configure it. The most basic config looks like this:

```php
MongoSession::config(array(
    'connection'    => 'mongodb://localhost:27017',
    'db'            => 'theDbName',
    'cookie_domain' => '.mydomain.com'
));
```

Replace `.mydomain.com` with your domain and change the connection string to any
valid MongoDB connection string as [described here](http://www.php.net/manual/en/mongo.connecting.php).

Now, when you're ready, just call:

```php
MongoSession::init();
```

You can pass `true` to `init(…)` the first time it runs so that you create the indices, but that's it.

Why, oh why?
============

If you've examined the code, I'm sure you have a few questions.

