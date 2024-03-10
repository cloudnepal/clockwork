<?php namespace Clockwork\DataSource;

use Clockwork\Helpers\Serializer;
use Clockwork\Helpers\StackTrace;
use Clockwork\Request\Request;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;

// Data source for Laravel HTTP client, provides executed HTTP requests
class LaravelHttpClientDataSource extends DataSource
{
	// Event dispatcher instance
	protected $dispatcher;

	// Sent HTTP requests
	protected $requests = [];
	
	// Map of executing requests, keyed by their object hash
	protected $executingRequests = [];
	
	// Create a new data source instance, takes an event dispatcher as argument
	public function __construct(Dispatcher $dispatcher)
	{
		$this->dispatcher = $dispatcher;
	}
	
	// Add sent notifications to the request
	public function resolve(Request $request)
	{
		$request->httpRequests = array_merge($request->httpRequests, $this->requests);
		
		return $request;
	}
	
	// Reset the data source to an empty state, clearing any collected data
	public function reset()
	{
		$this->requests = [];
		$this->executingRequests = [];
	}
	
	// Listen to the email and notification events
	public function listenToEvents()
	{
		$this->dispatcher->listen(ConnectionFailed::class, function ($event) { $this->connectionFailed($event); });
		$this->dispatcher->listen(RequestSending::class, function ($event) { $this->sendingRequest($event); });
		$this->dispatcher->listen(ResponseReceived::class, function ($event) { $this->responseReceived($event); });
	}
	
	// Collect an executing request
	protected function sendingRequest(RequestSending $event)
	{
		$trace = StackTrace::get()->resolveViewName();
		
		$request = (object) [
			'request'  => (object) [
				'method'  => $event->request->method(),
				'url'     => $this->removeAuthFromUrl($event->request->url()),
				'headers' => $event->request->headers(),
				'content' => $event->request->data(),
				'body'    => $event->request->body(),
			],
			'response' => null,
			'stats'    => null,
			'error'    => null, 
			'time'     => microtime(true),
			'trace'    => (new Serializer)->trace($trace)
		];
		
		if ($this->passesFilters([ $request ])) {
			$this->requests[] = $this->executingRequests[spl_object_hash($event->request)] = $request;
		}
	}

	// Update last request with response details and time taken
	protected function responseReceived($event)
	{
		if (! isset($this->executingRequests[spl_object_hash($event->request)])) return;
		
		$request = $this->executingRequests[spl_object_hash($event->request)];
		$stats = $event->response->handlerStats();
				
		$request->duration = (microtime(true) - $request->time) * 1000;
		$request->response = (object) [
			'status'  => $event->response->status(),
			'headers' => $event->response->headers(),
			'content' => $event->response->json(),
			'body'    => $event->response->body()
		];
		$request->stats = (object) [
			'timing' => isset($stats['total_time_us']) ? (object) [
				'lookup' => $stats['namelookup_time_us'] / 1000,
				'connect' => ($stats['pretransfer_time_us'] - $stats['namelookup_time_us']) / 1000,
				'waiting' => ($stats['starttransfer_time_us'] - $stats['pretransfer_time_us']) / 1000,
				'transfer' => ($stats['total_time_us'] - $stats['starttransfer_time_us']) / 1000
			] : null,
			'size' => (object) [
				'upload' => isset($stats['size_upload']) ? $stats['size_upload'] : null,
				'download' => isset($stats['size_download']) ? $stats['size_download'] : null
			],
			'speed' => (object) [
				'upload' => isset($stats['speed_upload']) ? $stats['speed_upload'] : null,
				'download' => isset($stats['speed_download']) ? $stats['speed_download'] : null
			],
			'hosts' => (object) [
				'local' => isset($stats['local_ip']) ? [ 'ip' => $stats['local_ip'], 'port' => $stats['local_port'] ] : null,
				'remote' => isset($stats['primary_ip']) ? [ 'ip' => $stats['primary_ip'], 'port' => $stats['primary_port'] ] : null
			],
			'version' => isset($stats['http_version']) ? $stats['http_version'] : null 
		];
		
		unset($this->executingRequests[spl_object_hash($event->request)]);
	}
	
	// Update last request with error when connection fails
	protected function connectionFailed($event)
	{
		if (! isset($this->executingRequests[spl_object_hash($event->request)])) return;

		$request = $this->executingRequests[spl_object_hash($event->request)];
		
		$request->duration = (microtime(true) - $request->time) * 1000;
		$request->error = 'connection-failed';

		unset($this->executingRequests[spl_object_hash($event->request)]);
	}

	// Removes username and password from the URL
	protected function removeAuthFromUrl($url)
	{
		return preg_replace('#^(.+?://)(.+?@)(.*)$#', '$1$3', $url);
	}
}
