+++
title = Mockery: returning values and throwing exceptions
date = 2015-04-24
author = Jacob Kiers
+++

Last week, I had to write a piece of code that contains retry logic. Naturally, I want to test it. That proved trickier than expected.

The application code looks like this:

```php
class Sender
{
    protected $connection;

    public function send()
    {
        $success = false;

        $i = 0;
        do {
            $i++;

            try {
                $success = $this->doSend($i);
            } catch (SenderException $e) {
                $success = false;
            }

        } while (!$success && $i < 3);

        return $success;
    }

    protected function doSend($data)
    {
        // Can throw SenderException
        $response = $this->connection->send($data);

        if ('OK' === $response) {
            return true;
        }

        return false;
    }
}
```

I specifically want to test the retry logic, so I have to mock the ::doSend() method. Then I can simulate the different outcomes (returning true or false, or throwing a SenderException).

I use [Mockery] to do the real work. It is a great library. If you don't know it yet, please check it out. I will wait right here...

Now, since ::doSend() is a protected method, Mockery must be instructed to allow that.

So after the first try, I ended up with:

```php
public function testItWillRetrySending()
{
    $sender = M::mock('Sender');
    $sender->shouldAllowMockingProtectedMethods()

    $sender->shouldReceive('doSend')
        ->andReturn(false, new Exception());
}
```

To my surprise, this did not work. Instead of throwing the exception, Mockery returns it. So my next try was this:

```php
$sender->shouldReceive('doSend')
    ->andReturn(false)
    ->andThrow(new Exception());
```

Another surprise: with this code, Mockery will always throw the exception, and ignore the first return value (false). After some debugging, I found out that Mockery just overwrites the return values in this case.

Fortunately, there is another way to return multiple return values: the ::andReturnUsing() method. It gives full control over the return values.

So I ended up with this testing code:

```php
$return_value_generator = function () {
    static $counter = 0;

    $counter++;

    switch ($counter) {
        case 1: return false;
        case 2: throw new SenderException();
        case 3: return true;
        default: throw new Exception("Should never reach this."); 
    }
};

$sender = M::mock('Sender');

$sender->shouldAllowMockingProtectedMethods()
    ->shouldReceive('doSend')
    ->andReturnUsing($return_value_generator);
```

This works perfectly. It feels a bit like a hack though. So if you know a better way or have any other remarks, please let me know.

[Mockery]: https://github.com/mockery/mockery