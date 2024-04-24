<?php
declare(strict_types = 1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Middleware\SessionMiddleware;
use Slim\Views\Twig;
use PDO;

class OrderController {
    private PDO $db;
    private Twig $view;
    private array $token;

    public function __construct(PDO $db, Twig $view, array $token) {
        $this->db = $db;
        $this->view = $view;
        $this->token = $token;
    }

    public function getOrders(Request $request, Response $response, $args) {

        if ($this->token['userId'] === null) {
            return $this->view->render($response->withStatus(401), 'error_user_not_found.twig', ['message' => 'User is not authenticated!']);
        }

        $stmt = $this->db->query('SELECT * FROM orders');
        $orders = $stmt->fetchAll();

        return $this->view->render($response, 'orders.twig', ['orders' => $orders]);
    }

    public function getByUserId(Request $request, Response $response, $args) {

        if ($this->token['userId'] === null) {
            return $this->view->render($response->withStatus(401), 'error__user_not_found.twig', ['message' => 'User is not authenticated!']);
        }

        $queryParams = $request->getQueryParams();
        $user_id = $queryParams['user_id'] ?? null;

        print_r($queryParams);
        if (!$user_id) {
            return $this->view->render($response->withStatus(404), 'error_order_not_found.twig', ['message' => 'Order not found']);
        }
        
        $sql = 'SELECT * FROM orders WHERE user_id = ?';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$user_id]);
        $orders = $stmt->fetchAll();

        return $this->view->render($response, 'orders.twig', ['orders' => $orders]);
    }

    public function createOrder(Request $request, Response $response, $args) {

        print_r($this->token);
        if ($this->token['userId'] == NULL) {
            return $this->view->render($response->withStatus(401), 'error_user_not_found.twig', ['message' => 'User is not authenticated!']);
        }

        $data = $request->getParsedBody();
        print_r($data);
        
        $userId = $data['user_id'] ?? null;
        $name = $data['name'] ?? null;
        $phone = $data['phone'] ?? null;
        $email = $data['email'] ?? null;
        $method = $data['method'] ?? null;
        $address = $data['address'] ?? null;
        $paymentStatus = $data['payment_status'] ?? null;
        $status = $data['status'] ?? null;
        $productIds = isset($data['productIds']) ? explode(',', $data['productIds']) : null;
        $quantities = isset($data['quantities']) ? explode(',', $data['quantities']) : null;
    
        if ($userId === null || $name === null || $phone === null || $email === null || $method === null || $address === null || $paymentStatus === null || $status === null) {
            return $this->view->render($response->withStatus(400), 'error.twig', ['message' => 'Missing required data']);
        }

        if (empty($productIds) || empty($quantities)) {
            return $this->view->render($response->withStatus(400), 'error.twig', ['message' => 'Product IDs and quantities are required']);
        }
    
        $userId = (int)$userId;
    
        $stmt = $this->db->prepare('INSERT INTO orders (user_id, name, phone, email, method, address, payment_status, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $name, $phone, $email, $method, $address, $paymentStatus, $status]);
    
        $orderId = $this->db->lastInsertId();
        print_r($orderId);
    
        foreach ($productIds as $key => $productId) {
            $quantity = isset($quantities[$key]) ? $quantities[$key] : 1;
            
            $pricePerItemStmt = $this->db->prepare('SELECT price FROM products WHERE id = ?');
            $pricePerItemStmt->execute([$productId]);
            $pricePerItem = $pricePerItemStmt->fetchColumn();
            
            $insertStmt = $this->db->prepare('INSERT INTO orders_items (order_id, product_id, quantity, price_per_item) VALUES (?, ?, ?, ?)');
            $insertStmt->execute([$orderId, $productId, $quantity, $pricePerItem]);
        }
        
    
        $getItem = $this->db->prepare('SELECT price_per_item FROM orders_items WHERE order_id = ?');
        $getItem->execute([$orderId]);
        $prices = $getItem->fetchAll(PDO::FETCH_COLUMN);
        $totalPrice = array_sum($prices);

        $updateStmt = $this->db->prepare('UPDATE orders SET total_price = ? WHERE id = ?');
        $updateStmt->execute([$totalPrice, $orderId]);
    
        return $this->view->render($response, 'create_order_success.twig');
    }
    
    
    

    public function updateOrder(Request $request, Response $response, $args) {

        if ($this->token['userId'] === null) {
            return $this->view->render($response->withStatus(401), 'error__user_not_found.twig', ['message' => 'User is not authenticated!']);
        }

        $orderId = $args['orderId'];
        $data = $request->getParsedBody();
    
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if (!$order) {
            return $this->view->render($response->withStatus(404), 'error_update.twig', ['message' => 'Order not found']);
        } else {
            $name = $data['name'] ?? $order['name'];
            $phone = $data['phone'] ?? $order['phone'];
            $email = $data['email'] ?? $order['email'];
            $method = $data['method'] ?? $order['method'];
            $address = $data['address'] ?? $order['address'];
            $placedOn = $data['placed_on'] ?? $order['placed_on'];
            $paymentStatus = $data['payment_status'] ?? $order['payment_status'];
            $totalPrice = $data['total_price'] ?? $order['total_price'];
            $status = $data['status'] ?? $order['status'];

            $stmt = $this->db->prepare('UPDATE orders SET name = ?, phone = ?, email = ?, method = ?, address = ?, placed_on = ?, payment_status = ?, total_price = ?, status = ? WHERE id = ?');
            $stmt->execute([$name, $phone, $email, $method, $address, $placedOn, $paymentStatus, $totalPrice, $status, $orderId]);
    
            return $this->view->render($response, 'update_order_success.twig', ['message' => 'Order updated successfully']);
        }
    }
    

    public function deleteOrder(Request $request, Response $response, $args) {
        if ($this->token['userId'] === null) {
            return $this->view->render($response->withStatus(401), 'error_user_not_found.twig', ['message' => 'User is not authenticated!']);
        }

        $orderId = $args['orderId'] ?? null;

        if ($orderId === null) {
            return $this->view->render($response->withStatus(400), 'error_order_not_found.twig', ['message' => 'Order ID is missing!']);
        }

        $stmt_products = $this->db->prepare('DELETE FROM orders_items WHERE order_id = ?');
        $stmt_products->execute([$orderId]);
        
        $stmt = $this->db->prepare('DELETE FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        
        return $this->view->render($response, 'delete_order_success.twig', ['message' => 'Order deleted successfully!']);
    }
    
}

?>