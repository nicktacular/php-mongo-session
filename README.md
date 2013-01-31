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

Sessions are hard
=================

Sessions are something we take for granted in PHP since they just work straight out of the box. You call
`session_start()` and things just work well and quickly. Done.

Ok, now that you're done developing on a single-machine architecture, let's briefly discuss multi-machine
environments. You can no longer rely on the tried-and-true file-based session management from PHP. Time
to use a centralized system.

So why not an existing session handler built on MySQL? Well, 95% of the features in MySQL aren't necessary
for session handling. MongoDB gives you exactly what you need and at a significant performance boost. Less
overhead is good, right?

The most important point I'd like to make after having dealt with a lot of pain of multi-server architectures
is that there are many variables that affect how your application will now work.

### File locks

PHP file-based sessions rely on file locks which are inherently released after a process completes,
regardless of whether or not the process crashed, `exit`ed, or ran out of memory. This makes file-based
sessions very reliable.

Unlike file-based sessions, using a data store like MongoDB requires the application to deal with the
locks. If your script runs out of memory and PHP crashes without the application releasing the lock,
you could end up with a session that's completely locked out to the user. I've been able to partially
replicate this problem on AWS EC2 Micro instances with a memory hungry app. Occasional crashes would
occur under heavy load causing PHP not to finish the session with `write()` and `close()` which would
invoking the unlock mechanism.

You can't test this type of behavior in a unit test. You must test your entire application under load
to ensure that you're running sufficient resources so that your application is running stable.

### MongoDB

If you're going to try running session handling on a single MongoDB instance, you're most likely
asking for trouble. Each application obviously has its own requirements and load so you need to
analyze this. However, running a replica set with 3 or more machines is a good place to start.

Why, oh why?
============

If you've examined the code, I'm sure you have a few questions.

**(Q) Why is this PHP 5.2 compatible? Nothing cool in PHP ever existed prior to 5.3**

(A) You're preaching to the choir, but it's a legacy app thing. I'll make it 5.4 flavored eventually.

**(Q) WHERE are your unit tests?**

(This is a question [@grmpyprogrammer](https://twitter.com/grmpyprogrammer) is surely asking right about now…)

(A) I'm working on it, but PHP sessions are inherently difficult to test. You can't really mock PHP calling your
session handler so I'm working on figuring out a test. Regardless, I'm using this in a production environment
so I as find optimizations or bugs, I'll be sure to post these here in addition to the unit test I'm trying to
wrap my head around.

**(Q) Where's `composer.json`??**

(A) Why don't you tweet about how this project doesn't have `composer.json`? I'll retweet you.


