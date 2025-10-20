<?php
require_once 'db.php';

/**
 * Manages all bakery operations by calling stored procedures.
 */
class BakeryManager {
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
    }

    // =============================================
    // Ingredient & Recipe Functions
    // =============================================

    /**
     * Adds a new ingredient to the database.
     * Calls: IngredientAdd(?, ?, ?, ?)
     */
    public function addIngredient($name, $unit, $stock_qty, $reorder_level) {
        $stmt = $this->conn->prepare("CALL IngredientAdd(?, ?, ?, ?)");
        return $stmt->execute([$name, $unit, $stock_qty, $reorder_level]);
    }

    /**
     * Adds stock to an existing ingredient.
     * Calls: IngredientRestock(?, ?)
     */
    public function restockIngredient($ingredient_id, $added_qty) {
        $stmt = $this->conn->prepare("CALL IngredientRestock(?, ?)");
        return $stmt->execute([$ingredient_id, $added_qty]);
    }

    /**
     * Adds an ingredient to a product's recipe.
     * Calls: RecipeAddIngredient(?, ?, ?)
     */
    public function addIngredientToRecipe($product_id, $ingredient_id, $qty_needed) {
        $stmt = $this->conn->prepare("CALL RecipeAddIngredient(?, ?, ?)");
        return $stmt->execute([$product_id, $ingredient_id, $qty_needed]);
    }

    /**
     * Gets the full recipe for a single product.
     * Calls: RecipeGetForProduct(?)
     */
    public function getRecipeForProduct($product_id) {
        $stmt = $this->conn->prepare("CALL RecipeGetForProduct(?)");
        $stmt->execute([$product_id]);
        return $stmt->fetchAll();
    }

    /**
     * --- NEWLY ADDED ---
     * Manually triggers a scan to find all low-stock ingredients.
     * Calls: IngredientCheckLowStock()
     */
    public function checkLowStock() {
        $stmt = $this->conn->prepare("CALL IngredientCheckLowStock()");
        return $stmt->execute();
    }

    /**
     * --- NEWLY ADDED ---
     * Marks a specific low-stock alert as resolved.
     * Calls: AlertMarkResolved(?)
     */
    public function resolveAlert($alert_id) {
        $stmt = $this->conn->prepare("CALL AlertMarkResolved(?)");
        return $stmt->execute([$alert_id]);
    }


    // =============================================
    // Product & Production Functions
    // =============================================

    /**
     * Adds a new finished product.
     * Calls: ProductAdd(?, ?)
     */
    public function addProduct($name, $price) {
        $stmt = $this->conn->prepare("CALL ProductAdd(?, ?)");
        return $stmt->execute([$name, $price]);
    }

    /**
     * Manually adjusts product stock for spoilage, etc.
     * Calls: ProductAdjustStock(?, ?, ?)
     */
    public function adjustProductStock($product_id, $adjustment_qty, $reason) {
        $stmt = $this->conn->prepare("CALL ProductAdjustStock(?, ?, ?)");
        return $stmt->execute([$product_id, $adjustment_qty, $reason]);
    }

    /**
     * Records a baking run, which deducts ingredients and adds product stock.
     * Calls: ProductionRecordBaking(?, ?, @status)
     */
    public function recordBaking($product_id, $qty_baked) {
        $stmt = $this->conn->prepare("CALL ProductionRecordBaking(?, ?, @status)");
        return $stmt->execute([$product_id, $qty_baked]);
    }

    // =============================================
    // Sales & Recall Functions
    // =============================================

    /**
     * Records a new sale transaction, deducting product stock.
     * Calls: SaleRecordTransaction(?, ?, ?, @status, @sale_id)
     */
    public function recordSale($user_id, $product_id, $qty_sold) {
        $stmt = $this->conn->prepare("CALL SaleRecordTransaction(?, ?, ?, @status, @sale_id)");
        return $stmt->execute([$user_id, $product_id, $qty_sold]);
    }

    /**
     * Initiates a product recall.
     * Calls: RecallInitiate(?, ?, ?, ?)
     */
    public function initiateRecall($product_id, $reason, $batch_start_date, $batch_end_date) {
        $stmt = $this->conn->prepare("CALL RecallInitiate(?, ?, ?, ?)");
        return $stmt->execute([$product_id, $reason, $batch_start_date, $batch_end_date]);
    }

    /**
     * --- NEWLY ADDED ---
     * Logs the physical removal of recalled items from stock.
     * Calls: RecallLogRemoval(?, ?, ?, ?)
     */
    public function logRecallRemoval($recall_id, $user_id, $qty_removed, $notes) {
        $stmt = $this->conn->prepare("CALL RecallLogRemoval(?, ?, ?, ?)");
        return $stmt->execute([$recall_id, $user_id, $qty_removed, $notes]);
    }

    // =============================================
    // User & Report Functions
    // =============================================

    /**
     * Authenticates a user.
     * Calls: UserLogin(?, ?)
     */
    public function userLogin($username, $password) {
        $stmt = $this->conn->prepare("CALL UserLogin(?, ?)");
        $stmt->execute([$username, $password]);
        return $stmt->fetch();
    }

    /**
     * Gets the best-selling products in a date range.
     * Calls: ReportGetBestSellers(?, ?)
     */
    public function getBestSellers($date_start, $date_end) {
        $stmt = $this->conn->prepare("CALL ReportGetBestSellers(?, ?)");
        $stmt->execute([$date_start, $date_end]);
        return $stmt->fetchAll();
    }

    /**
     * Gets sales data grouped by cashier.
     * Calls: ReportGetSalesByCashier(?, ?)
     */
    public function getSalesByCashier($date_start, $date_end) {
        $stmt = $this->conn->prepare("CALL ReportGetSalesByCashier(?, ?)");
        $stmt->execute([$date_start, $date_end]);
        return $stmt->fetchAll();
    }

    /**
     * Gets all active low-stock alerts from the view.
     * Calls: view_ActiveLowStockAlerts
     */
    public function getActiveLowStockAlerts() {
        $stmt = $this->conn->prepare("SELECT * FROM view_ActiveLowStockAlerts");
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
?>