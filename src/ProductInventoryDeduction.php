<?php
require_once 'db_connection.php';

class ProductInventoryDeduction {
    private $conn;

    public function __construct($connection) {
        $this->conn = $connection;
    }

    public function deductInventoryForProduct($productId) {
        try {
            $this->conn->begin_transaction();

            $productQuery = "SELECT * FROM products WHERE product_id = ?";
            $stmt = $this->conn->prepare($productQuery);
            $stmt->bind_param("i", $productId);
            $stmt->execute();
            $productResult = $stmt->get_result();

            if ($productResult->num_rows === 0) {
                throw new Exception("Product not found");
            }

            $product = $productResult->fetch_assoc();
            $recipeQuery = "SELECT ingredient_id, quantity_needed FROM recipes WHERE product_id = ?";
            $stmt = $this->conn->prepare($recipeQuery);
            $stmt->bind_param("i", $productId);
            $stmt->execute();
            $recipeResult = $stmt->get_result();

            while ($recipe = $recipeResult->fetch_assoc()) {
                $ingredientId = $recipe['ingredient_id'];
                $quantityNeeded = $recipe['quantity_needed'];

                $inventoryQuery = "SELECT quantity FROM inventory WHERE ingredient_id = ?";
                $stmt = $this->conn->prepare($inventoryQuery);
                $stmt->bind_param("i", $ingredientId);
                $stmt->execute();
                $inventoryResult = $stmt->get_result();

                if ($inventoryResult->num_rows === 0) {
                    throw new Exception("Ingredient ID $ingredientId not found in inventory");
                }

                $inventory = $inventoryResult->fetch_assoc();
                $currentQuantity = $inventory['quantity'];

                if ($currentQuantity < $quantityNeeded) {
                    throw new Exception("Insufficient inventory for ingredient ID $ingredientId");
                }

                $updateQuery = "UPDATE inventory SET quantity = quantity - ? WHERE ingredient_id = ?";
                $stmt = $this->conn->prepare($updateQuery);
                $stmt->bind_param("di", $quantityNeeded, $ingredientId);

                if (!$stmt->execute()) {
                    throw new Exception("Failed to update inventory for ingredient ID $ingredientId");
                }
            }

            $this->conn->commit();
            return array(
                'success' => true,
                'message' => 'Inventory deducted successfully for product ' . $product['name']
            );

        } catch (Exception $e) {
            $this->conn->rollback();
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }

    public function deductInventoryForMultipleProducts($productId, $quantity) {
        try {
            $this->conn->begin_transaction();

            $productQuery = "SELECT * FROM products WHERE product_id = ?";
            $stmt = $this->conn->prepare($productQuery);
            $stmt->bind_param("i", $productId);
            $stmt->execute();
            $productResult = $stmt->get_result();

            if ($productResult->num_rows === 0) {
                throw new Exception("Product not found");
            }

            $product = $productResult->fetch_assoc();
            $recipeQuery = "SELECT ingredient_id, quantity_needed FROM recipes WHERE product_id = ?";
            $stmt = $this->conn->prepare($recipeQuery);
            $stmt->bind_param("i", $productId);
            $stmt->execute();
            $recipeResult = $stmt->get_result();

            while ($recipe = $recipeResult->fetch_assoc()) {
                $ingredientId = $recipe['ingredient_id'];
                $totalQuantityNeeded = $recipe['quantity_needed'] * $quantity;

                $inventoryQuery = "SELECT quantity FROM inventory WHERE ingredient_id = ?";
                $stmt = $this->conn->prepare($inventoryQuery);
                $stmt->bind_param("i", $ingredientId);
                $stmt->execute();
                $inventoryResult = $stmt->get_result();

                if ($inventoryResult->num_rows === 0) {
                    throw new Exception("Ingredient ID $ingredientId not found in inventory");
                }

                $inventory = $inventoryResult->fetch_assoc();
                $currentQuantity = $inventory['quantity'];

                if ($currentQuantity < $totalQuantityNeeded) {
                    throw new Exception("Insufficient inventory for ingredient ID $ingredientId. Required: $totalQuantityNeeded, Available: $currentQuantity");
                }

                $updateQuery = "UPDATE inventory SET quantity = quantity - ? WHERE ingredient_id = ?";
                $stmt = $this->conn->prepare($updateQuery);
                $stmt->bind_param("di", $totalQuantityNeeded, $ingredientId);

                if (!$stmt->execute()) {
                    throw new Exception("Failed to update inventory for ingredient ID $ingredientId");
                }
            }

            $this->conn->commit();
            return array(
                'success' => true,
                'message' => "Inventory deducted successfully for $quantity unit(s) of " . $product['name']
            );

        } catch (Exception $e) {
            $this->conn->rollback();
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }

    public function checkInventoryAvailability($productId, $quantity = 1) {
        try {
            $recipeQuery = "SELECT r.ingredient_id, r.quantity_needed, i.quantity, ing.name
                           FROM recipes r
                           JOIN inventory i ON r.ingredient_id = i.ingredient_id
                           JOIN ingredients ing ON r.ingredient_id = ing.ingredient_id
                           WHERE r.product_id = ?";
            $stmt = $this->conn->prepare($recipeQuery);
            $stmt->bind_param("i", $productId);
            $stmt->execute();
            $result = $stmt->get_result();

            $availability = array();
            $canProduce = true;

            while ($row = $result->fetch_assoc()) {
                $requiredQuantity = $row['quantity_needed'] * $quantity;
                $available = $row['quantity'];
                $sufficient = $available >= $requiredQuantity;

                if (!$sufficient) {
                    $canProduce = false;
                }

                $availability[] = array(
                    'ingredient' => $row['name'],
                    'required' => $requiredQuantity,
                    'available' => $available,
                    'sufficient' => $sufficient
                );
            }

            return array(
                'can_produce' => $canProduce,
                'details' => $availability
            );

        } catch (Exception $e) {
            return array(
                'can_produce' => false,
                'error' => $e->getMessage()
            );
        }
    }
}
?>
