* Add custom defer handlers for the coroutine 
* Add custom handlers for Scope
* Add a function that executes these handlers along with the concurrent iterator
* The ASYNC API method for retrieving exception classes needs to be modified so that it can also be used to retrieve classes like Coroutine and Scope.
* We need to decide where exactly the CancellationException will be defined.