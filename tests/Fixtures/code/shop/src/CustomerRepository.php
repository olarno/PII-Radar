<?php

final class CustomerRepository
{
    public function __construct(private PDO $pdo, private LoggerInterface $logger, private Client $http)
    {
    }

    public function register(): void
    {
        $email = $_POST['email'];
        $stmt = $this->pdo->prepare('INSERT INTO customers (email, first_name, password) VALUES (?, ?, ?)');
        $stmt->execute([$email, $_POST['first_name'], password_hash($_POST['password'], PASSWORD_DEFAULT)]);

        $this->logger->info('New customer', ['email' => $email]);
        $this->http->post('https://hooks.example-crm.com/v1/contacts', ['json' => ['email' => $email]]);
    }

    public function search(): array
    {
        $sql = 'SELECT id, first_name FROM customers';
        $sql .= " WHERE email = '{$_GET['email']}'";

        return $this->pdo->query($sql)->fetchAll();
    }
}
