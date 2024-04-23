<?php
namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response;

class SessionMiddleware
{
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // Check if session is already active
        if (!isset($_SESSION)) {
            session_start(); // Start session if not already started
        }

        // Call the next middleware
        $response = $handler->handle($request);

        // Optionally, you can perform actions after the request has been processed

        return $response;
    }
}
?>