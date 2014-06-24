<?php namespace Credibility\MandrillLaravel;

use Closure;
use Illuminate\Support\Facades\Config;
use Swift_Mailer;
use Swift_Message;
use Illuminate\Log\Writer;
use Illuminate\View\Environment;
use Illuminate\Queue\QueueManager;
use Illuminate\Container\Container;
use Illuminate\Support\SerializableClosure;
use ScubaClick\Mandrill;

class Mailer {

    private $app;

    protected $swift;

    protected $from;

    protected $logger;

    protected $container;

    protected $pretending = false;

    protected $client;


    /**
     * Create a new Mailer instance.
     *
     * @param  \Illuminate\View\Environment $views
     * @param  \Swift_Mailer $swift
     * @param $app
     * @return \Credibility\MandrillLaravel\Mailer
     */
    public function __construct(Environment $views, Swift_Mailer $swift, $app)
    {
        $this->app = $app;
    }

    /**
     * Set the global from address and name.
     *
     * @param  string  $address
     * @param  string  $name
     * @return void
     */
    public function alwaysFrom($address, $name = null)
    {
        $this->from = compact('address', 'name');
    }

    /**
     * Send a new message when only a plain part.
     *
     * @param  string  $view
     * @param  array   $data
     * @param  mixed   $callback
     * @return int
     */
    public function plain($view, array $data, $callback)
    {
        return $this->send(array('text' => $view), $data, $callback);
    }

    /**
     * Send a new message using a view.
     *
     * @param  string|array  $view The template name
     * @param  array  $data
     * @param  Closure|string  $callback
     * @return int
     */
    public function send($view, array $data, $callback = null)
    {
        $mergeVars = array();

        foreach(Config::get("mandrill-laravel::content.".$view.".merge_vars") as $key => $conv) {
            $temp['name'] = $key;
            $temp['content'] = $conv($data);
            $mergeVars[] = $temp;
        }

        $emailFunc = Config::get("mandrill-laravel::content.".$view.".email");
        $customConfig = Config::get("mandrill-laravel::content.".$view.".custom");

        if(is_callable($customConfig)){
            $customConfig = $customConfig($data);
            if(!is_array($customConfig)){
                $customConfig = array($customConfig);
            }
        }

        $customData = array_merge(
            Config::get("mandrill-laravel::global_configs"),
            is_array($customConfig) ? $customConfig : array()
        );

        $finalData = array(
            'email' => $emailFunc($data),
            'merge_vars' => $mergeVars,
            'custom' => $customData
        );

        return $this->sendMandrillTemplate($view, $finalData);
    }

    /**
     * Sends email via Mandrill
     */
    protected function sendMandrillTemplate($template, $data)
    {
        $request = array(
            'template_name' => $template,
            'template_content' => array(),
            'message' => array(
                'from_email' => Config::get("mandrill-laravel::from_email"),
                'to' => array(
                    array('email' => $data['email'])
                ),
                'merge_vars' => array(
                    array(
                        'rcpt' => $data['email'],
                        'vars' => $data['merge_vars']
                    )
                )
            )
        );

        $request['message'] = array_merge($request['message'], $data['custom']);

        return \Mandrill::sendEmailTemplate($request);
    }

    /**
     * Queue a new e-mail message for sending after (n) seconds.
     *
     * @param  int  $delay
     * @param  string|array  $view
     * @param  array  $data
     * @param  Closure|string  $callback
     * @param  string  $queue
     * @return void
     */
    public function later($delay, $view, array $data, $callback, $queue = null)
    {
        $callback = $this->buildQueueCallable($callback);

        $this->queue->later($delay, 'mailer@handleQueuedMessage', compact('view', 'data', 'callback'), $queue);
    }

    /**
     * Queue a new e-mail message for sending after (n) seconds on the given queue.
     *
     * @param  string  $queue
     * @param  int  $delay
     * @param  string|array  $view
     * @param  array  $data
     * @param  Closure|string  $callback
     * @return void
     */
    public function laterOn($queue, $delay, $view, array $data, $callback)
    {
        $this->later($delay, $view, $data, $callback, $queue);
    }

    /**
     * Build the callable for a queued e-mail job.
     *
     * @param  mixed  $callback
     * @return mixed
     */
    protected function buildQueueCallable($callback)
    {
        if ( ! $callback instanceof Closure) return $callback;

        return serialize(new SerializableClosure($callback));
    }

    /**
     * Handle a queued e-mail message job.
     *
     * @param  \Illuminate\Queue\Jobs\Job  $job
     * @param  array  $data
     * @return void
     */
    public function handleQueuedMessage($job, $data)
    {
        $this->send($data['view'], $data['data'], $this->getQueuedCallable($data));

        $job->delete();
    }

    /**
     * Get the true callable for a queued e-mail message.
     *
     * @param  array  $data
     * @return mixed
     */
    protected function getQueuedCallable(array $data)
    {
        if (str_contains($data['callback'], 'SerializableClosure'))
        {
            return with(unserialize($data['callback']))->getClosure();
        }

        return $data['callback'];
    }

    /**
     * Add the content to a given message.
     *
     * @param  \Illuminate\Mail\Message  $message
     * @param  string  $view
     * @param  string  $plain
     * @param  array   $data
     * @return void
     */
    protected function addContent($message, $view, $plain, $data)
    {
        if (isset($view))
        {
            $message->setBody($this->getView($view, $data), 'text/html');
        }

        if (isset($plain))
        {
            $message->addPart($this->getView($plain, $data), 'text/plain');
        }
    }

    /**
     * Parse the given view name or array.
     *
     * @param  string|array  $view
     * @return array
     */
    protected function parseView($view)
    {
        if (is_string($view)) return array($view, null);

        // If the given view is an array with numeric keys, we will just assume that
        // both a "pretty" and "plain" view were provided, so we will return this
        // array as is, since must should contain both views with numeric keys.
        if (is_array($view) and isset($view[0]))
        {
            return $view;
        }

        // If the view is an array, but doesn't contain numeric keys, we will assume
        // the the views are being explicitly specified and will extract them via
        // named keys instead, allowing the developers to use one or the other.
        elseif (is_array($view))
        {
            return array(
                array_get($view, 'html'), array_get($view, 'text')
            );
        }

        throw new \InvalidArgumentException("Invalid view.");
    }

    /**
     * Send a Swift Message instance.
     *
     * @param  \Swift_Message  $message
     * @return int
     */
    protected function sendSwiftMessage($message)
    {
        if ( ! $this->pretending)
        {
            var_dump($message);exit;
//            return $this->swift->send($message);
        }
        elseif (isset($this->logger))
        {
            $this->logMessage($message);
        }
    }

    /**
     * Log that a message was sent.
     *
     * @param  \Swift_Message  $message
     * @return void
     */
    protected function logMessage($message)
    {
        $emails = implode(', ', array_keys((array) $message->getTo()));

        $this->logger->info("Pretending to mail message to: {$emails}");
    }

    /**
     * Call the provided message builder.
     *
     * @param  Closure|string  $callback
     * @param  \Illuminate\Mail\Message  $message
     * @return mixed
     */
    protected function callMessageBuilder($callback, $message)
    {
        if ($callback instanceof Closure)
        {
            return call_user_func($callback, $message);
        }
        elseif (is_string($callback))
        {
            return $this->container[$callback]->mail($message);
        }

        throw new \InvalidArgumentException("Callback is not valid.");
    }

    /**
     * Create a new message instance.
     *
     * @return \Illuminate\Mail\Message
     */
    protected function createMessage()
    {
        $message = new Message(new Swift_Message);

        // If a global from address has been specified we will set it on every message
        // instances so the developer does not have to repeat themselves every time
        // they create a new message. We will just go ahead and push the address.
        if (isset($this->from['address']))
        {
            $message->from($this->from['address'], $this->from['name']);
        }

        return $message;
    }


    /**
     * Tell the mailer to not really send messages.
     *
     * @param  bool  $value
     * @return void
     */
    public function pretend($value = true)
    {
        $this->pretending = $value;
    }


    /**
     * Get the Swift Mailer instance.
     *
     * @return \Swift_Mailer
     */
    public function getSwiftMailer()
    {
        return $this->swift;
    }

    /**
     * Set the Swift Mailer instance.
     *
     * @param  \Swift_Mailer  $swift
     * @return void
     */
    public function setSwiftMailer($swift)
    {
        $this->swift = $swift;
    }

    /**
     * Set the log writer instance.
     *
     * @param  \Illuminate\Log\Writer  $logger
     * @return \Illuminate\Mail\Mailer
     */
    public function setLogger(Writer $logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Set the queue manager instance.
     *
     * @param  \Illuminate\Queue\QueueManager  $queue
     * @return \Illuminate\Mail\Mailer
     */
    public function setQueue(QueueManager $queue)
    {
        $this->queue = $queue;

        return $this;
    }

    /**
     * Set the IoC container instance.
     *
     * @param  \Illuminate\Container\Container  $container
     * @return void
     */
    public function setContainer(Container $container)
    {
        $this->container = $container;
    }

}

