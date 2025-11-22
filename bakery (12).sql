-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 21, 2025 at 03:09 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `bakery`
--

DELIMITER $$
--
-- Procedures
--
CREATE  PROCEDURE `AdminDeleteUser` (IN `p_user_id` INT)   BEGIN
    DELETE FROM users WHERE user_id = p_user_id;
END$$

CREATE  PROCEDURE `AdminGetAllUsers` ()   BEGIN
    SELECT user_id, username, role, email, phone_number, created_at 
    FROM users 
    ORDER BY role, username;
END$$

CREATE  PROCEDURE `AdminGetManagers` ()   BEGIN
    SELECT user_id, username, phone_number
    FROM users
    WHERE role IN ('manager', 'assistant_manager') 
      AND phone_number IS NOT NULL 
      AND phone_number != '';
END$$

CREATE  PROCEDURE `AdminGetMySettings` (IN `p_user_id` INT)   BEGIN
    SELECT phone_number, enable_daily_report 
    FROM users 
    WHERE user_id = p_user_id;
END$$

CREATE  PROCEDURE `AdminGetUsersForDailyReport` ()   BEGIN
    SELECT phone_number 
    FROM users 
    WHERE 
        role = 'manager' 
        AND enable_daily_report = 1
        AND phone_number IS NOT NULL 
        AND phone_number != '';
END$$

CREATE  PROCEDURE `AdminUpdateMySettings` (IN `p_user_id` INT, IN `p_phone_number` VARCHAR(12), IN `p_enable_report` TINYINT)   BEGIN
    UPDATE users
    SET 
        phone_number = p_phone_number,
        enable_daily_report = p_enable_report
    WHERE user_id = p_user_id;
END$$

CREATE  PROCEDURE `AdminUpdateUser` (IN `p_user_id` INT, IN `p_username` VARCHAR(100), IN `p_password` VARCHAR(255), IN `p_role` ENUM('manager','cashier','assistant_manager'), IN `p_email` VARCHAR(150), IN `p_phone` VARCHAR(11))   BEGIN
    UPDATE users
    SET 
        username = p_username,
        password = IF(p_password IS NOT NULL AND p_password != '', p_password, password),
        role = p_role,
        email = p_email,
        phone_number = p_phone
    WHERE user_id = p_user_id;
END$$

CREATE  PROCEDURE `DashboardGetActiveLowStockAlerts` (IN `p_limit` INT)   BEGIN
    SELECT * FROM view_ActiveLowStockAlerts
    ORDER BY current_stock ASC
    LIMIT p_limit;
END$$

CREATE  PROCEDURE `DashboardGetLowStockAlertsCount` ()   BEGIN
    SELECT COUNT(*) AS alertCount FROM view_ActiveLowStockAlerts;
END$$

CREATE  PROCEDURE `DashboardGetRecalledStockValue` (IN `p_date_start` DATE, IN `p_date_end` DATE)   BEGIN
    SELECT
        -- Use COALESCE to ensure it returns 0.00 instead of NULL if no recalls exist
        -- The value is negative (qty) * price, so we use SUM to add all negative values.
        -- We then multiply by -1 at the end to display it as a positive "cost" (e.g., P50.00)
        COALESCE(SUM(
            CASE
                -- Only calculate value for *removed* (negative qty) *products*
                WHEN sa.item_type = 'product' AND sa.adjustment_qty < 0 THEN sa.adjustment_qty * p.price
                ELSE 0
            END
        ), 0.00) * -1 AS totalRecalledValue
    FROM
        stock_adjustments sa
    LEFT JOIN
        products p ON sa.item_id = p.product_id AND sa.item_type = 'product'
    WHERE
        -- Find all adjustments marked as recall
        sa.reason LIKE '%recall%'
        -- AND filter by the provided date range
        AND DATE(sa.timestamp) BETWEEN p_date_start AND p_date_end;
END$$

CREATE  PROCEDURE `DashboardGetSalesSummaryByDateRange` (IN `p_date_start` DATE, IN `p_date_end` DATE)   BEGIN
    SELECT
        COUNT(sale_id) AS totalSales,
        SUM(total_price) AS totalRevenue
    FROM
        sales
    WHERE
        -- MODIFIED: Use DATE() to filter the timestamp column
        DATE(`timestamp`) BETWEEN p_date_start AND p_date_end;
END$$

CREATE  PROCEDURE `IngredientAdd` (IN `name` VARCHAR(100), IN `unit` VARCHAR(50), IN `stock_qty` FLOAT, IN `reorder_level` FLOAT)   BEGIN
    INSERT INTO ingredients(name, unit, stock_qty, reorder_level)
    VALUES (name, unit, stock_qty, reorder_level);

    SELECT LAST_INSERT_ID() AS new_ingredient_id;
END$$

CREATE  PROCEDURE `IngredientAdjustStock` (IN `p_ingredient_id` INT, IN `p_user_id` INT, IN `p_adjustment_qty` FLOAT, IN `p_reason` VARCHAR(255), IN `p_expiration_date` DATE)   BEGIN
    -- Validation
    IF (p_reason LIKE '[Restock]%' AND p_adjustment_qty <= 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Error: Restock quantity must be a positive number.';
    ELSEIF (p_reason LIKE '[Spoilage]%' AND p_adjustment_qty >= 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Error: Spoilage quantity must be a negative number.';
    END IF;

    -- Logic
    IF p_adjustment_qty > 0 THEN
        -- ADDING STOCK: Create a new batch
        INSERT INTO ingredient_batches (ingredient_id, quantity, expiration_date, date_received)
        VALUES (p_ingredient_id, p_adjustment_qty, p_expiration_date, CURDATE());
        
    ELSE
        -- REMOVING STOCK: Use FEFO Logic
        -- Pass positive value to the helper
        CALL IngredientReduceStockBatchFEFO(p_ingredient_id, ABS(p_adjustment_qty));
    END IF;

    -- Log the adjustment (Keep generic log for history)
    INSERT INTO stock_adjustments (item_id, item_type, user_id, adjustment_qty, reason)
    VALUES (p_ingredient_id, 'ingredient', p_user_id, p_adjustment_qty, p_reason);

    -- Resolve Alerts
    -- (This logic remains similar, view_IngredientStockLevel handles the aggregation)
    UPDATE alerts a
    JOIN view_IngredientStockLevel i ON a.ingredient_id = i.ingredient_id
    SET a.status = 'resolved'
    WHERE a.ingredient_id = p_ingredient_id
      AND a.status = 'unread'
      AND i.stock_qty > i.reorder_level;
      
    -- Check for low stock
    CALL IngredientCheckLowStock();
END$$

CREATE  PROCEDURE `IngredientCheckLowStock` ()   BEGIN
    INSERT INTO alerts (ingredient_id, message, date_triggered)
    SELECT
        ingredient_id,
        CONCAT('Low stock for ', name, '. Current: ', stock_qty, ', Reorder: ', reorder_level),
        CURDATE()
    FROM ingredients
    WHERE stock_qty <= reorder_level
    AND ingredient_id NOT IN (
        SELECT ingredient_id FROM alerts WHERE status = 'unread'
    );
END$$

CREATE  PROCEDURE `IngredientDelete` (IN `p_ingredient_id` INT, OUT `p_status` VARCHAR(255))   BEGIN
    DECLARE recipe_count INT;

    -- Check if the ingredient is used in any recipes
    SELECT COUNT(*) INTO recipe_count
    FROM recipes
    WHERE ingredient_id = p_ingredient_id;

    IF recipe_count > 0 THEN
        SET p_status = 'Error: Ingredient is used in recipes and cannot be deleted.';
    ELSE
        -- Delete from alerts first to avoid constraint issues
        DELETE FROM alerts WHERE ingredient_id = p_ingredient_id;

        -- Now, delete the ingredient
        DELETE FROM ingredients WHERE ingredient_id = p_ingredient_id;

        SET p_status = 'Success: Ingredient deleted.';
    END IF;
END$$

CREATE  PROCEDURE `IngredientGetAllSimple` ()   BEGIN
    SELECT ingredient_id, name, unit 
    FROM ingredients 
    ORDER BY name;
END$$

CREATE  PROCEDURE `IngredientReduceStockBatchFEFO` (IN `p_ingredient_id` INT, IN `p_qty_to_remove` FLOAT)   BEGIN
    DECLARE v_remaining_qty FLOAT DEFAULT p_qty_to_remove;
    DECLARE v_batch_id INT;
    DECLARE v_batch_qty FLOAT;
    DECLARE done INT DEFAULT FALSE;
    
    -- Cursor to fetch batches sorted by expiration date (NULLs last or first depending on policy, usually first/oldest)
    DECLARE cur CURSOR FOR 
        SELECT batch_id, quantity 
        FROM ingredient_batches 
        WHERE ingredient_id = p_ingredient_id AND quantity > 0
        ORDER BY (expiration_date IS NULL), expiration_date ASC, batch_id ASC;
        
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    OPEN cur;
    
    read_loop: LOOP
        FETCH cur INTO v_batch_id, v_batch_qty;
        IF done OR v_remaining_qty <= 0 THEN
            LEAVE read_loop;
        END IF;

        IF v_batch_qty >= v_remaining_qty THEN
            -- This batch has enough
            UPDATE ingredient_batches 
            SET quantity = quantity - v_remaining_qty 
            WHERE batch_id = v_batch_id;
            
            SET v_remaining_qty = 0;
        ELSE
            -- Take everything from this batch
            UPDATE ingredient_batches 
            SET quantity = 0 
            WHERE batch_id = v_batch_id;
            
            SET v_remaining_qty = v_remaining_qty - v_batch_qty;
        END IF;
    END LOOP;
    
    CLOSE cur;
    
    -- Clean up empty batches (Optional, keeping them might be good for history, but let's delete 0s to keep table small)
    DELETE FROM ingredient_batches WHERE quantity = 0;
END$$

CREATE  PROCEDURE `IngredientUpdate` (IN `p_ingredient_id` INT, IN `p_name` VARCHAR(100), IN `p_unit` VARCHAR(50), IN `p_reorder_level` FLOAT)   BEGIN
    UPDATE ingredients
    SET
        name = p_name,
        unit = p_unit,
        reorder_level = p_reorder_level
    WHERE ingredient_id = p_ingredient_id;
END$$

CREATE  PROCEDURE `InventoryGetDiscontinued` ()   BEGIN
    SELECT * FROM view_DiscontinuedProducts ORDER BY name;
END$$

CREATE  PROCEDURE `InventoryGetIngredients` ()   BEGIN
    SELECT
        ingredient_id,
        name,
        unit,
        ROUND(stock_qty, 2) AS stock_qty,
        reorder_level,
        stock_surplus
    FROM
        view_IngredientStockLevel
    ORDER BY
        name;
END$$

CREATE  PROCEDURE `InventoryGetProducts` ()   BEGIN
    SELECT * FROM view_ProductInventory ORDER BY name;
END$$

CREATE  PROCEDURE `InventoryGetRecallHistory` (IN `p_date_start` DATE, IN `p_date_end` DATE)   BEGIN
    SELECT
        sa.timestamp,
        u.username,
        sa.item_type,
        -- Use COALESCE to get the name from the correct table
        COALESCE(p.name, i.name) AS item_name,
        sa.adjustment_qty,
        sa.reason,
        -- Calculate the value of removed product stock
        CASE
            WHEN sa.item_type = 'product' AND sa.adjustment_qty < 0 THEN sa.adjustment_qty * p.price
            ELSE 0
        END AS removed_value
    FROM
        stock_adjustments sa
    LEFT JOIN
        users u ON sa.user_id = u.user_id
    LEFT JOIN
        products p ON sa.item_id = p.product_id AND sa.item_type = 'product'
    LEFT JOIN
        ingredients i ON sa.item_id = i.ingredient_id AND sa.item_type = 'ingredient'
    WHERE
        -- Filter for adjustments where the reason contains "recall"
        sa.reason LIKE '%recall%'
        -- Filter by the provided date range
        AND DATE(sa.timestamp) BETWEEN p_date_start AND p_date_end
    ORDER BY
        sa.timestamp DESC;
END$$

CREATE  PROCEDURE `LogLoginAttempt` (IN `p_user_id` INT, IN `p_username` VARCHAR(100), IN `p_status` ENUM('success','failure'), IN `p_device_type` VARCHAR(50))   BEGIN
    INSERT INTO login_history (user_id, username_attempt, status, device_type)
    VALUES (p_user_id, p_username, p_status, p_device_type);
END$$

CREATE  PROCEDURE `PosGetAvailableProducts` ()   BEGIN
    SELECT product_id, name, price, stock_qty, image_url -- <-- ADDED image_url
    FROM view_ProductInventory
    WHERE status = 'available' AND stock_qty > 0
    ORDER BY name;
END$$

CREATE  PROCEDURE `ProductAdd` (IN `p_name` VARCHAR(100), IN `p_price` DECIMAL(10,2), IN `p_image_url` VARCHAR(255))   BEGIN
    INSERT INTO products(name, price, image_url, stock_qty, status) -- <-- ADDED image_url
    VALUES (p_name, p_price, p_image_url, 0, 'available'); -- <-- ADDED p_image_url

    SELECT LAST_INSERT_ID() AS new_product_id;
END$$

CREATE  PROCEDURE `ProductAdjustStock` (IN `p_product_id` INT, IN `p_user_id` INT, IN `p_adjustment_qty` INT, IN `p_reason` VARCHAR(255), OUT `p_status` VARCHAR(255))   BEGIN
    DECLARE v_batch_size INT;
    DECLARE v_num_batches FLOAT;
    DECLARE v_qty_adjusted INT;
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_ing_id INT;
    DECLARE v_qty_needed_base FLOAT;
    DECLARE v_stock_qty_base FLOAT;
    
    -- Cursor for Recipe Ingredients
    DECLARE cur_recipe CURSOR FOR 
        SELECT 
            r.ingredient_id,
            (r.qty_needed * uc_req.to_base_factor) / uc_stock.to_base_factor * v_num_batches AS qty_to_deduct
        FROM recipes r
        JOIN ingredients i ON r.ingredient_id = i.ingredient_id
        JOIN unit_conversions uc_req ON r.unit = uc_req.unit
        JOIN unit_conversions uc_stock ON i.unit = uc_stock.unit
        WHERE r.product_id = p_product_id;
        
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    SET v_qty_adjusted = p_adjustment_qty;

    -- ... (Keep existing error checking logic for 0 qty or simple manual removal) ...
    IF p_adjustment_qty = 0 THEN
        SET p_status = 'Error: Quantity cannot be zero.';
    ELSEIF p_adjustment_qty < 0 AND (LOWER(p_reason) NOT LIKE '%correction%') THEN
         -- Simple Product Removal (Spoilage/Recall) - No Ingredient impact
        UPDATE products SET stock_qty = stock_qty + p_adjustment_qty WHERE product_id = p_product_id;
        INSERT INTO stock_adjustments (item_id, item_type, user_id, adjustment_qty, reason) VALUES (p_product_id, 'product', p_user_id, p_adjustment_qty, p_reason);
        SET p_status = 'Success: Stock removed.';
    ELSE
        -- PRODUCTION or CORRECTION
        SELECT batch_size INTO v_batch_size FROM products WHERE product_id = p_product_id;
        IF v_batch_size = 0 OR v_batch_size IS NULL THEN SET v_batch_size = 1; END IF;
        SET v_num_batches = v_qty_adjusted / v_batch_size;

        -- 1. Validate Stock First (Simplified)
        IF v_num_batches > 0 THEN
             -- (Logic to check if enough stock exists in view_IngredientStockLevel omitted for brevity, assume UI checked it)
             -- You can add a SELECT COUNT(*) ... check here against view_IngredientStockLevel
             SET p_status = 'Processing...'; 
        END IF;

        -- 2. Transaction
        START TRANSACTION;
            -- Adjust Ingredients
            OPEN cur_recipe;
            read_loop: LOOP
                FETCH cur_recipe INTO v_ing_id, v_qty_needed_base;
                IF done THEN LEAVE read_loop; END IF;

                IF v_num_batches > 0 THEN
                    -- Production: Reduce Ingredient Stock
                    CALL IngredientReduceStockBatchFEFO(v_ing_id, v_qty_needed_base);
                ELSE
                    -- Correction (Negative Production): Add Ingredient Stock back
                    -- We add it as a "Correction" batch with today's date
                    INSERT INTO ingredient_batches (ingredient_id, quantity, expiration_date, date_received)
                    VALUES (v_ing_id, ABS(v_qty_needed_base), NULL, CURDATE());
                END IF;
            END LOOP;
            CLOSE cur_recipe;

            -- Adjust Product Stock
            UPDATE products SET stock_qty = stock_qty + v_qty_adjusted WHERE product_id = p_product_id;
            
            -- Log
            INSERT INTO stock_adjustments (item_id, item_type, user_id, adjustment_qty, reason) VALUES (p_product_id, 'product', p_user_id, v_qty_adjusted, p_reason);
            IF v_num_batches > 0 THEN
                INSERT INTO production (product_id, qty_baked, date) VALUES (p_product_id, v_qty_adjusted, CURDATE());
            END IF;
        COMMIT;
        SET p_status = 'Success: Stock updated.';
    END IF;
END$$

CREATE  PROCEDURE `ProductDelete` (IN `p_product_id` INT, OUT `p_status` VARCHAR(255))   BEGIN
    DECLARE sales_count INT;
    DECLARE production_count INT;
    DECLARE recipe_count INT; -- Also check if it's used in recipes (though less likely for finished product)

    -- Check for associated sales
    SELECT COUNT(*) INTO sales_count
    FROM sales
    WHERE product_id = p_product_id;

    -- Check for associated production runs
    SELECT COUNT(*) INTO production_count
    FROM production
    WHERE product_id = p_product_id;

    -- Check if it's somehow directly in a recipe (unlikely but possible)
    SELECT COUNT(*) INTO recipe_count
    FROM recipes
    WHERE product_id = p_product_id;

    IF sales_count > 0 THEN
        SET p_status = 'Error: Product has sales records and cannot be deleted. Mark as "Discontinued" instead.';
    ELSEIF production_count > 0 THEN
        SET p_status = 'Error: Product has production records and cannot be deleted. Mark as "Discontinued" instead.';
    ELSEIF recipe_count > 0 THEN
        SET p_status = 'Error: Product is used in recipes and cannot be deleted.';
    ELSE
        -- Okay to delete. Need to remove dependencies first if any exist (e.g., recalls)
        DELETE FROM product_recalls WHERE product_id = p_product_id;
        -- Now delete the product
        DELETE FROM products WHERE product_id = p_product_id;
        SET p_status = 'Success: Product deleted.';
    END IF;
END$$

CREATE  PROCEDURE `ProductGetAllSimple` ()   BEGIN
    SELECT product_id, name, batch_size
    FROM products 
    WHERE status = 'available' 
    ORDER BY name;
END$$

CREATE  PROCEDURE `ProductGetById` (IN `p_product_id` INT)   BEGIN
    SELECT * FROM products WHERE product_id = p_product_id;
END$$

CREATE  PROCEDURE `ProductUpdate` (IN `p_product_id` INT, IN `p_name` VARCHAR(100), IN `p_price` DECIMAL(10,2), IN `p_status` ENUM('available','recalled','discontinued'), IN `p_image_url` VARCHAR(255))   BEGIN
    UPDATE products
    SET
        name = p_name,
        price = p_price,
        status = p_status,
        -- If p_image_url is NULL, keep the old one. If it's a path, update it.
        image_url = IF(p_image_url IS NULL, image_url, p_image_url) 
    WHERE product_id = p_product_id;
END$$

CREATE  PROCEDURE `ProductUpdateBatchSize` (IN `p_product_id` INT, IN `p_batch_size` INT)   BEGIN
    UPDATE products
    SET batch_size = p_batch_size
    WHERE product_id = p_product_id;
END$$

CREATE  PROCEDURE `RecallInitiate` (IN `product_id` INT, IN `reason` TEXT, IN `batch_start_date` DATE, IN `batch_end_date` DATE)   BEGIN
    START TRANSACTION;

    -- 1. Set the product status to 'recalled'
    UPDATE products
    SET status = 'recalled'
    WHERE products.product_id = product_id;

    -- 2. Log the recall event
    INSERT INTO product_recalls (product_id, reason, recall_date, status, affected_batch_date_start, affected_batch_date_end)
    VALUES (product_id, reason, CURDATE(), 'active', batch_start_date, batch_end_date);

    COMMIT;
    SELECT LAST_INSERT_ID() AS new_recall_id;
END$$

CREATE  PROCEDURE `RecallLogRemoval` (IN `recall_id` INT, IN `user_id` INT, IN `qty_removed_from_stock` INT, IN `notes` TEXT)   BEGIN
    DECLARE product_id INT;

    -- Get the product_id from the recall
    SELECT product_recalls.product_id INTO product_id
    FROM product_recalls
    WHERE product_recalls.recall_id = recall_id;

    START TRANSACTION;

    -- 1. Log the removal
    INSERT INTO recalled_stock_log (recall_id, user_id, qty_removed, date_removed, notes)
    VALUES (recall_id, user_id, qty_removed_from_stock, NOW(), notes);

    -- 2. Remove that quantity from the main product stock
    UPDATE products
    SET stock_qty = stock_qty - qty_removed_from_stock
    WHERE products.product_id = product_id;

    COMMIT;
END$$

CREATE  PROCEDURE `RecipeAddIngredient` (IN `p_product_id` INT, IN `p_ingredient_id` INT, IN `p_qty_needed` FLOAT, IN `p_unit` VARCHAR(50))   BEGIN
    -- Check for duplicates first
    IF NOT EXISTS (SELECT 1 FROM recipes WHERE product_id = p_product_id AND ingredient_id = p_ingredient_id) THEN
        INSERT INTO recipes (product_id, ingredient_id, qty_needed, unit)
        VALUES (p_product_id, p_ingredient_id, p_qty_needed, p_unit);
    END IF;
END$$

CREATE  PROCEDURE `RecipeGetByProductId` (IN `p_product_id` INT)   BEGIN
    SELECT 
        r.recipe_id,
        i.name,
        r.qty_needed,
        r.unit
    FROM 
        recipes r
    JOIN 
        ingredients i ON r.ingredient_id = i.ingredient_id
    WHERE 
        r.product_id = p_product_id
    ORDER BY
        i.name;
END$$

CREATE  PROCEDURE `RecipeRemoveIngredient` (IN `p_recipe_id` INT)   BEGIN
    DELETE FROM recipes WHERE recipe_id = p_recipe_id;
END$$

CREATE  PROCEDURE `ReportGetBestSellers` (IN `date_start` DATE, IN `date_end` DATE)   BEGIN
    SELECT
        p.name,
        SUM(s.qty_sold) AS total_units_sold,
        SUM(s.total_price) AS total_revenue
    FROM sales s
    JOIN products p ON s.product_id = p.product_id
    WHERE DATE(s.timestamp) BETWEEN date_start AND date_end
    GROUP BY p.product_id, p.name
    ORDER BY total_units_sold DESC;
END$$

CREATE  PROCEDURE `ReportGetLoginHistory` ()   BEGIN
    SELECT
        lh.timestamp,
        lh.username_attempt,
        lh.status,
        lh.device_type, -- <-- ADDED THIS
        u.role
    FROM
        login_history lh
    LEFT JOIN
        users u ON lh.user_id = u.user_id
    ORDER BY
        lh.timestamp DESC
    LIMIT 200;
END$$

CREATE  PROCEDURE `ReportGetReturnHistory` ()   BEGIN
    SELECT
        r.sale_id, -- <-- ADDED THIS
        r.timestamp,
        p.name AS product_name,
        r.qty_returned,
        r.return_value,
        u.username,
        r.reason
    FROM
        `returns` r
    LEFT JOIN
        products p ON r.product_id = p.product_id
    LEFT JOIN
        users u ON r.user_id = u.user_id
    ORDER BY
        r.timestamp DESC;
END$$

CREATE  PROCEDURE `ReportGetSalesHistory` (IN `p_date_start` DATE, IN `p_date_end` DATE, IN `p_sort_column` VARCHAR(50), IN `p_sort_direction` VARCHAR(4))   BEGIN
    SET @order_dir_asc = (UPPER(p_sort_direction) = 'ASC');

    SELECT
        s.sale_id,
        s.timestamp AS date,
        p.name AS product_name,
        s.qty_sold,
        
        -- --- NEW CALCULATIONS ---
        -- Calculate original subtotal (unit price * qty)
        (p.price * s.qty_sold) AS subtotal, 
        
        -- Calculate discount amount (Subtotal - Final Price)
        -- s.total_price already has the discount applied
        (p.price * s.qty_sold) - s.total_price AS discount_amount,
        -- --- END NEW ---

        s.total_price, -- This is the final (net) price
        s.discount_percent, 
        s.qty_returned, 
        u.username AS cashier_username
    FROM
        sales s
    LEFT JOIN
        products p ON s.product_id = p.product_id
    LEFT JOIN
        users u ON s.user_id = u.user_id
    WHERE
        DATE(s.timestamp) BETWEEN p_date_start AND p_date_end
    ORDER BY
        CASE WHEN p_sort_column = 'product' AND @order_dir_asc THEN p.name END ASC,
        CASE WHEN p_sort_column = 'product' AND NOT @order_dir_asc THEN p.name END DESC,
        CASE WHEN p_sort_column = 'cashier' AND @order_dir_asc THEN u.username END ASC,
        CASE WHEN p_sort_column = 'cashier' AND NOT @order_dir_asc THEN u.username END DESC,
        CASE WHEN p_sort_column = 'qty' AND @order_dir_asc THEN s.qty_sold END ASC,
        CASE WHEN p_sort_column = 'qty' AND NOT @order_dir_asc THEN s.qty_sold END DESC,
        
        -- --- NEW SORT LOGIC ---
        CASE WHEN p_sort_column = 'subtotal' AND @order_dir_asc THEN (p.price * s.qty_sold) END ASC,
        CASE WHEN p_sort_column = 'subtotal' AND NOT @order_dir_asc THEN (p.price * s.qty_sold) END DESC,
        CASE WHEN p_sort_column = 'discount_amt' AND @order_dir_asc THEN ((p.price * s.qty_sold) - s.total_price) END ASC,
        CASE WHEN p_sort_column = 'discount_amt' AND NOT @order_dir_asc THEN ((p.price * s.qty_sold) - s.total_price) END DESC,
        -- --- END NEW ---
        
        CASE WHEN p_sort_column = 'price' AND @order_dir_asc THEN s.total_price END ASC, -- 'price' is NET
        CASE WHEN p_sort_column = 'price' AND NOT @order_dir_asc THEN s.total_price END DESC,
        CASE WHEN p_sort_column = 'discount' AND @order_dir_asc THEN s.discount_percent END ASC, -- 'discount' is the PERCENT
        CASE WHEN p_sort_column = 'discount' AND NOT @order_dir_asc THEN s.discount_percent END DESC,
        
        CASE WHEN p_sort_column = 'date' AND @order_dir_asc THEN s.timestamp END ASC,
        CASE WHEN p_sort_column = 'date' AND NOT @order_dir_asc THEN s.timestamp END DESC,
        
        s.timestamp DESC, s.sale_id DESC;
END$$

CREATE  PROCEDURE `ReportGetSalesSummaryByDate` (IN `p_date_start` DATE, IN `p_date_end` DATE)   BEGIN
    SELECT 
        p.name AS product_name,
        SUM(s.qty_sold) AS total_qty_sold,
        SUM(s.total_price) AS total_revenue
    FROM 
        sales s
    JOIN 
        products p ON s.product_id = p.product_id
    WHERE 
        -- MODIFIED: Use DATE() to filter the timestamp column
        DATE(s.timestamp) BETWEEN p_date_start AND p_date_end
    GROUP BY 
        p.product_id, p.name
    ORDER BY 
        total_qty_sold DESC;
END$$

CREATE  PROCEDURE `ReportGetSalesSummaryToday` ()   BEGIN
    SELECT 
        COUNT(sale_id) AS totalSales,
        SUM(total_price) AS totalRevenue
    FROM 
        sales
    WHERE 
        -- MODIFIED: Use DATE() to filter the timestamp column
        DATE(timestamp) = CURDATE();
END$$

CREATE  PROCEDURE `ReportGetStockAdjustmentHistory` ()   BEGIN
    SELECT
        sa.timestamp,
        u.username,
        sa.item_type,
        -- Use COALESCE to get the name from the correct table
        COALESCE(p.name, i.name) AS item_name,
        sa.adjustment_qty,
        sa.reason
    FROM
        stock_adjustments sa
    LEFT JOIN
        users u ON sa.user_id = u.user_id
    LEFT JOIN
        products p ON sa.item_id = p.product_id AND sa.item_type = 'product'
    LEFT JOIN
        ingredients i ON sa.item_id = i.ingredient_id AND sa.item_type = 'ingredient'
    ORDER BY
        sa.timestamp DESC
    LIMIT 200; -- Add a limit for performance
END$$

CREATE  PROCEDURE `ReportGetStockAdjustmentHistoryByDate` (IN `p_date_start` DATE, IN `p_date_end` DATE)   BEGIN
    SELECT 
        sa.timestamp,
        u.username,
        COALESCE(p.name, i.name) AS item_name,
        sa.item_type,
        sa.adjustment_qty,
        sa.reason
    FROM 
        stock_adjustments sa
    LEFT JOIN 
        users u ON sa.user_id = u.user_id
    LEFT JOIN 
        products p ON sa.item_id = p.product_id AND sa.item_type = 'product'
    LEFT JOIN 
        ingredients i ON sa.item_id = i.ingredient_id AND sa.item_type = 'ingredient'
    WHERE 
        DATE(sa.timestamp) BETWEEN p_date_start AND p_date_end
    ORDER BY 
        sa.timestamp DESC;
END$$

CREATE  PROCEDURE `ReportGetUnsoldProducts` (IN `p_date_start` DATE, IN `p_date_end` DATE)   BEGIN
    SELECT
        p.product_id,
        p.name,
        p.stock_qty
    FROM
        products p
    LEFT JOIN (
        -- Subquery to find all products that HAD a sale in the range
        SELECT DISTINCT s.product_id
        FROM sales s
        WHERE DATE(s.timestamp) BETWEEN p_date_start AND p_date_end
    ) AS sold_products ON p.product_id = sold_products.product_id
    WHERE
        p.status = 'available'  -- Only show available products
        AND p.is_sellable = 1   -- Only show products meant for POS
        AND sold_products.product_id IS NULL -- This is the key: it was NOT sold
    ORDER BY
        p.name;
END$$

CREATE  PROCEDURE `SaleProcessReturn` (IN `p_sale_id` INT, IN `p_user_id` INT, IN `p_return_qty` INT, IN `p_reason` VARCHAR(255))   BEGIN
    DECLARE v_product_id INT;
    DECLARE v_original_qty INT;
    DECLARE v_already_returned INT;
    DECLARE v_max_returnable INT;
    DECLARE v_unit_price DECIMAL(10,2);
    DECLARE v_return_value DECIMAL(10,2);

    -- Get original sale details and lock the row
    SELECT product_id, qty_sold, (total_price / qty_sold), qty_returned
    INTO v_product_id, v_original_qty, v_unit_price, v_already_returned
    FROM sales
    WHERE sale_id = p_sale_id
    FOR UPDATE;

    -- Calculate max returnable
    SET v_max_returnable = v_original_qty - v_already_returned;

    -- Validate
    IF v_product_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Error: Original sale not found.';
    END IF;
    
    IF p_return_qty <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Error: Return quantity must be a positive number.';
    END IF;
    
    IF p_return_qty > v_max_returnable THEN
         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Error: Cannot return more. Only %s items are available to be returned from this sale.';
    END IF;

    -- Calculate the value being "refunded"
    SET v_return_value = v_unit_price * p_return_qty;
    
    START TRANSACTION;

    -- 1. Add the stock back to the product
    UPDATE products
    SET stock_qty = stock_qty + p_return_qty
    WHERE product_id = v_product_id;

    -- 2. Log the return in the new 'returns' table
    INSERT INTO `returns` (sale_id, product_id, user_id, qty_returned, return_value, reason, timestamp)
    VALUES (p_sale_id, v_product_id, p_user_id, p_return_qty, v_return_value, p_reason, NOW());
    
    -- 3. Update the 'sales' table to reflect this return
    UPDATE sales
    SET qty_returned = qty_returned + p_return_qty
    WHERE sale_id = p_sale_id;
    
    COMMIT;
END$$

CREATE  PROCEDURE `SaleRecordTransaction` (IN `user_id` INT, IN `product_id` INT, IN `qty_sold` INT, IN `p_discount_percent` DECIMAL(5,2), OUT `status` VARCHAR(100), OUT `sale_id` INT)   BEGIN
    DECLARE current_stock INT;
    DECLARE product_price DECIMAL(10,2);
    DECLARE product_status VARCHAR(20); -- Fixed data type
    DECLARE total_price DECIMAL(10,2);

    -- Get product info and lock the row for update
    SELECT products.stock_qty, products.price, products.status
    INTO current_stock, product_price, product_status
    FROM products
    WHERE products.product_id = product_id
    FOR UPDATE;

    -- Check if product is sellable
    IF product_status != 'available' THEN
        SET status = CONCAT('Error: Product is ', product_status, '.');
        SET sale_id = -1;
    -- Check if stock is sufficient
    ELSEIF current_stock < qty_sold THEN
        SET status = 'Error: Insufficient stock.';
        SET sale_id = -1;
    ELSE
        START TRANSACTION;

        -- 1. Calculate total price, applying the discount
        SET total_price = (product_price * qty_sold) * (1 - (p_discount_percent / 100.0));

        -- 2. Deduct from product stock
        UPDATE products
        SET stock_qty = stock_qty - qty_sold
        WHERE products.product_id = product_id;

        -- 3. Record the sale (MODIFIED TO INCLUDE DISCOUNT)
        INSERT INTO sales (product_id, user_id, qty_sold, total_price, discount_percent, timestamp)
        VALUES (product_id, user_id, qty_sold, total_price, p_discount_percent, NOW());

        SET sale_id = LAST_INSERT_ID();
        SET status = 'Success: Sale recorded.';

        COMMIT;
    END IF;
END$$

CREATE  PROCEDURE `UserCheckAvailability` (IN `p_username` VARCHAR(100), IN `p_email` VARCHAR(150), IN `p_phone` VARCHAR(11))   BEGIN
    SELECT user_id 
    FROM users 
    WHERE username = p_username 
       OR email = p_email 
       -- Only check phone if it's not empty/null to allow multiple users without phones
       OR (p_phone IS NOT NULL AND p_phone != '' AND phone_number = p_phone)
    LIMIT 1;
END$$

CREATE  PROCEDURE `UserCheckAvailabilityForUpdate` (IN `p_user_id` INT, IN `p_username` VARCHAR(100), IN `p_email` VARCHAR(150), IN `p_phone` VARCHAR(11))   BEGIN
    SELECT user_id 
    FROM users 
    WHERE (username = p_username OR email = p_email OR (p_phone IS NOT NULL AND p_phone != '' AND phone_number = p_phone))
      AND user_id != p_user_id
    LIMIT 1;
END$$

CREATE  PROCEDURE `UserCreateAccount` (IN `p_username` VARCHAR(100), IN `p_hashed_password` VARCHAR(255), IN `p_role` ENUM('manager','cashier','assistant_manager'), IN `p_email` VARCHAR(150), IN `p_phone` VARCHAR(11))   BEGIN
    -- FIX: Changed p_phone_number to p_phone to match the parameter above
    INSERT INTO users (username, password, role, email, phone_number)
    VALUES (p_username, p_hashed_password, p_role, p_email, p_phone);
END$$

CREATE  PROCEDURE `UserFindById` (IN `p_user_id` INT)   BEGIN
    SELECT user_id, username, role, email, phone_number FROM users WHERE user_id = p_user_id;
END$$

CREATE  PROCEDURE `UserFindByPhone` (IN `p_phone_number` VARCHAR(11))   BEGIN
    SELECT * FROM users WHERE phone_number = p_phone_number;
END$$

CREATE  PROCEDURE `UserLogin` (IN `p_username` VARCHAR(100))   BEGIN
    -- This LOWER() function fixes the case-sensitive login (e.g., Camile123 vs camile123)
    SELECT * FROM users WHERE LOWER(username) = LOWER(p_username);
END$$

CREATE  PROCEDURE `UserRequestPasswordReset` (IN `email` VARCHAR(150), OUT `token` VARCHAR(255))   BEGIN
    DECLARE user_id INT;

    SELECT users.user_id INTO user_id FROM users WHERE users.email = email;

    IF user_id IS NOT NULL THEN
        SET token = UUID(); -- Generate a unique token

        INSERT INTO password_resets (user_id, reset_token, expiration)
        VALUES (user_id, token, NOW() + INTERVAL 1 HOUR);
    ELSE
        SET token = NULL;
    END IF;
END$$

CREATE  PROCEDURE `UserResetPassword` (IN `p_token_or_otp` VARCHAR(255), IN `p_new_hashed_password` VARCHAR(255))   BEGIN
    DECLARE v_user_id INT;
    DECLARE v_reset_id INT;
    DECLARE v_status VARCHAR(100) DEFAULT 'Error';

    -- Find a valid, unused reset entry that is not expired
    SELECT reset_id, user_id INTO v_reset_id, v_user_id
    FROM password_resets
    WHERE (reset_token = p_token_or_otp OR otp_code = p_token_or_otp)
      AND used = 0
      AND expiration > NOW()
    LIMIT 1;

    IF v_user_id IS NOT NULL THEN
        START TRANSACTION;
        
        -- 1. Update the user's password
        UPDATE users
        SET password = p_new_hashed_password
        WHERE user_id = v_user_id;
        
        -- 2. Mark the token/otp as used
        UPDATE password_resets
        SET used = 1
        WHERE reset_id = v_reset_id;
        
        COMMIT;
        SET v_status = 'Success';
    ELSE
        SET v_status = 'Error: Invalid or expired code.';
    END IF;
    
    SELECT v_status AS status;
END$$

CREATE  PROCEDURE `UserStorePhoneOTP` (IN `p_user_id` INT, IN `p_otp_code` VARCHAR(10), IN `p_expiration_time` DATETIME)   BEGIN
    INSERT INTO password_resets (user_id, reset_method, otp_code, expiration, used)
    VALUES (p_user_id, 'phone_otp', p_otp_code, p_expiration_time, 0);
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `alerts`
--

CREATE TABLE `alerts` (
  `alert_id` int(11) NOT NULL,
  `ingredient_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `status` enum('unread','resolved') NOT NULL DEFAULT 'unread',
  `date_triggered` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `alerts`
--

INSERT INTO `alerts` (`alert_id`, `ingredient_id`, `message`, `status`, `date_triggered`) VALUES
(1, 30, 'Low stock for Desiccated Coconut. Current: 0.25, Reorder: 1', 'resolved', '2025-11-07');

-- --------------------------------------------------------

--
-- Table structure for table `ingredients`
--

CREATE TABLE `ingredients` (
  `ingredient_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `stock_qty` float DEFAULT 0,
  `reorder_level` float DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ingredients`
--

INSERT INTO `ingredients` (`ingredient_id`, `name`, `unit`, `stock_qty`, `reorder_level`) VALUES
(6, 'Bread Flour', 'kg', 32, 10),
(7, 'All-Purpose Flour', 'kg', 27, 10),
(8, 'Sugar (White)', 'kg', 23.95, 5),
(9, 'Sugar (Brown)', 'kg', 10, 2),
(10, 'Salt', 'kg', 4.66, 1),
(11, 'Yeast (Instant)', 'g', 1105, 100),
(12, 'Butter (Unsalted)', 'kg', 9.9, 2),
(13, 'Margarine', 'kg', 9.4, 2),
(14, 'Eggs', 'tray', 4.93333, 2),
(15, 'Water', 'L', 5.95, 5),
(16, 'Full Cream Milk', 'L', 9.5, 3),
(17, 'Evaporated Milk', 'can', 40, 10),
(18, 'Condensed Milk', 'can', 14, 5),
(19, 'Cheddar Cheese', 'kg', 5, 1),
(20, 'Chocolate Chips (Dark)', 'kg', 2.7, 1),
(21, 'Cinnamon Powder', 'g', 250, 50),
(22, 'Ube Halaya', 'kg', 5, 1),
(23, 'Hotdog', 'pack', 30, 10),
(24, 'Tuna (in can)', 'can', 40, 10),
(25, 'Garlic Powder', 'g', 500, 100),
(26, 'Corned Beef (in can)', 'can', 30, 10),
(27, 'Chicken Floss', 'kg', 2, 0.5),
(28, 'Banana', 'kg', 15, 3),
(29, 'Cocoa Powder', 'kg', 2, 0.5),
(30, 'Desiccated Coconut', 'kg', 21.5, 1),
(31, 'Yema Spread', 'kg', 3, 1),
(32, 'Raisins', 'kg', 2, 0.5),
(33, 'Cream Cheese', 'kg', 4, 1),
(34, 'Baking Powder', 'g', 500, 100),
(35, 'Instant Coffee Powder', 'g', 300, 50),
(36, 'Whole Wheat Flour', 'kg', 10, 2),
(37, 'Olive Oil', 'L', 1.966, 0.5);

-- --------------------------------------------------------

--
-- Table structure for table `ingredient_batches`
--

CREATE TABLE `ingredient_batches` (
  `batch_id` int(11) NOT NULL,
  `ingredient_id` int(11) NOT NULL,
  `quantity` float NOT NULL DEFAULT 0,
  `expiration_date` date DEFAULT NULL,
  `date_received` date NOT NULL DEFAULT curdate()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ingredient_batches`
--

INSERT INTO `ingredient_batches` (`batch_id`, `ingredient_id`, `quantity`, `expiration_date`, `date_received`) VALUES
(1, 6, 32, NULL, '2025-11-20'),
(2, 7, 24.5, '2026-02-13', '2025-11-20'),
(3, 8, 23.175, NULL, '2025-11-20'),
(4, 9, 10, NULL, '2025-11-20'),
(5, 10, 4.66, NULL, '2025-11-20'),
(6, 11, 55, NULL, '2025-11-20'),
(7, 12, 9.66, NULL, '2025-11-20'),
(8, 13, 9.1, NULL, '2025-11-20'),
(9, 14, 4.66666, NULL, '2025-11-20'),
(10, 15, 5.95, NULL, '2025-11-20'),
(11, 16, 8.5, NULL, '2025-11-20'),
(12, 17, 40, NULL, '2025-11-20'),
(13, 18, 14, NULL, '2025-11-20'),
(14, 19, 4, NULL, '2025-11-20'),
(15, 20, 2.7, NULL, '2025-11-20'),
(16, 21, 250, NULL, '2025-11-20'),
(17, 22, 5, NULL, '2025-11-20'),
(18, 23, 30, NULL, '2025-11-20'),
(19, 24, 40, NULL, '2025-11-20'),
(20, 25, 500, NULL, '2025-11-20'),
(21, 26, 30, NULL, '2025-11-20'),
(22, 27, 2, NULL, '2025-11-20'),
(23, 28, 3, '2025-11-26', '2025-11-20'),
(24, 29, 2, NULL, '2025-11-20'),
(25, 30, 21.5, NULL, '2025-11-20'),
(26, 31, 3, NULL, '2025-11-20'),
(27, 32, 2, NULL, '2025-11-20'),
(28, 33, 4, NULL, '2025-11-20'),
(29, 34, 480, NULL, '2025-11-20'),
(30, 35, 300, NULL, '2025-11-20'),
(31, 36, 10, NULL, '2025-11-20'),
(32, 37, 1.966, NULL, '2025-11-20'),
(67, 28, 10, '2025-11-28', '2025-11-21'),
(68, 11, 1000, '2026-01-31', '2025-11-21');

-- --------------------------------------------------------

--
-- Table structure for table `login_history`
--

CREATE TABLE `login_history` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'NULL if login failed',
  `username_attempt` varchar(100) NOT NULL,
  `status` enum('success','failure') NOT NULL,
  `device_type` varchar(50) DEFAULT 'Unknown',
  `timestamp` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login_history`
--

INSERT INTO `login_history` (`log_id`, `user_id`, `username_attempt`, `status`, `device_type`, `timestamp`) VALUES
(18, 2, 'klain123', 'failure', 'Desktop', '2025-11-17 21:39:14'),
(19, 3, 'gian123', 'success', 'Desktop', '2025-11-17 21:39:18'),
(20, 2, 'klain123', 'failure', 'Mobile', '2025-11-17 21:43:02'),
(30, 2, 'klain123', 'failure', 'Mobile', '2025-11-17 21:50:02'),
(31, 2, 'klain123', 'failure', 'Mobile', '2025-11-17 21:50:18'),
(32, 3, 'gian123', 'success', 'Mobile', '2025-11-17 21:50:31'),
(33, 2, 'klain123', 'failure', 'Mobile', '2025-11-17 21:50:52'),
(34, 2, 'klain123', 'failure', 'Mobile', '2025-11-17 21:51:29'),
(35, 2, 'klain123', 'failure', 'Mobile', '2025-11-17 21:51:38'),
(36, 2, 'klain123', 'failure', 'Mobile', '2025-11-17 21:51:50'),
(37, 2, 'klain123', 'failure', 'Mobile', '2025-11-17 21:51:53'),
(38, 2, 'klain123', 'success', 'Mobile', '2025-11-17 21:51:58'),
(39, 2, 'klain123', 'failure', 'Mobile', '2025-11-17 21:55:18'),
(40, 2, 'klain123', 'success', 'Mobile', '2025-11-17 21:58:40'),
(41, 3, 'gian123', 'success', 'Mobile', '2025-11-17 21:58:58'),
(42, 3, 'Gian123', 'success', 'Mobile', '2025-11-17 22:12:26'),
(43, 2, 'klain123', 'failure', 'Desktop', '2025-11-17 22:26:50'),
(44, 2, 'klain123', 'success', 'Desktop', '2025-11-17 22:26:53'),
(45, 3, 'gian123', 'success', 'Desktop', '2025-11-17 22:27:32'),
(46, 2, 'klain123', 'failure', 'Mobile', '2025-11-17 22:30:11'),
(47, 2, 'klain123', 'success', 'Mobile', '2025-11-17 22:30:25'),
(48, 3, 'gian123', 'success', 'Desktop', '2025-11-17 22:31:04'),
(49, 3, 'gian123', 'success', 'Desktop', '2025-11-17 22:47:48'),
(50, 3, 'Gian123', 'success', 'Mobile', '2025-11-18 00:10:44'),
(51, 3, 'gian123', 'success', 'Mobile', '2025-11-18 00:43:27'),
(52, 2, 'klain123', 'failure', 'Mobile', '2025-11-18 00:46:56'),
(53, 2, 'klain123', 'success', 'Mobile', '2025-11-18 00:47:10'),
(54, 3, 'gian123', 'success', 'Desktop', '2025-11-18 00:47:13'),
(55, 3, 'gian123', 'success', 'Desktop', '2025-11-19 20:25:53'),
(56, 2, 'klain123', 'success', 'Mobile', '2025-11-19 20:27:08'),
(57, 2, 'klain123', 'success', 'Mobile', '2025-11-19 21:17:30'),
(58, NULL, 'camile123', 'success', 'Desktop', '2025-11-19 22:51:27'),
(59, 2, 'klain123', 'success', 'Desktop', '2025-11-19 22:55:09'),
(60, 3, 'gian123', 'success', 'Desktop', '2025-11-19 23:03:15'),
(61, 3, 'gian123', 'success', 'Desktop', '2025-11-19 23:56:39'),
(62, 4, 'camile123', 'failure', 'Desktop', '2025-11-20 00:39:32'),
(63, 4, 'camile123', 'success', 'Desktop', '2025-11-20 00:41:59'),
(64, 4, 'camile123', 'success', 'Desktop', '2025-11-20 00:42:10'),
(65, 3, 'gian123', 'success', 'Desktop', '2025-11-20 00:53:15'),
(66, 4, 'camile123', 'success', 'Desktop', '2025-11-20 02:22:17'),
(67, 4, 'camile123', 'success', 'Desktop', '2025-11-20 07:16:02'),
(68, 3, 'gian123', 'success', 'Desktop', '2025-11-20 07:30:05'),
(69, 3, 'gian123', 'success', 'Mobile', '2025-11-21 02:00:11'),
(70, 3, 'gian123', 'success', 'Mobile', '2025-11-21 02:01:53'),
(71, 4, 'camile123', 'failure', 'Desktop', '2025-11-21 02:45:28'),
(72, 3, 'gian123', 'success', 'Desktop', '2025-11-21 02:45:31'),
(73, 4, 'camile123', 'success', 'Desktop', '2025-11-21 02:46:07'),
(74, 3, 'gian123', 'success', 'Desktop', '2025-11-21 02:46:50');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `reset_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `reset_method` enum('email_token','phone_otp') NOT NULL DEFAULT 'email_token',
  `reset_token` varchar(255) DEFAULT NULL,
  `otp_code` varchar(10) DEFAULT NULL,
  `expiration` datetime DEFAULT NULL,
  `used` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`reset_id`, `user_id`, `reset_method`, `reset_token`, `otp_code`, `expiration`, `used`) VALUES
(1, 3, 'phone_otp', NULL, '174387', '2025-10-28 23:58:26', 1),
(2, 3, 'phone_otp', NULL, '470422', '2025-10-29 00:33:25', 1),
(3, 3, 'phone_otp', NULL, '122628', '2025-10-29 17:41:31', 1),
(4, 3, 'phone_otp', NULL, '835657', '2025-10-29 17:44:32', 1),
(5, 3, 'phone_otp', NULL, '022090', '2025-10-30 21:21:43', 1),
(6, 3, 'phone_otp', NULL, '819411', '2025-10-30 21:31:35', 1),
(7, 3, 'phone_otp', NULL, '447255', '2025-10-30 21:34:54', 1),
(8, 3, 'phone_otp', NULL, '241755', '2025-10-30 21:37:58', 1),
(9, 3, 'phone_otp', NULL, '347496', '2025-10-30 21:41:59', 1),
(10, 3, 'phone_otp', NULL, '665319', '2025-10-30 21:48:47', 1),
(11, 3, 'phone_otp', NULL, '392128', '2025-10-30 22:57:37', 1),
(15, 3, 'phone_otp', NULL, '740743', '2025-11-07 09:25:37', 0),
(16, 3, 'phone_otp', NULL, '925491', '2025-11-11 23:05:22', 1),
(17, 3, 'email_token', 'e7c0148c-c55c-11f0-be5d-c01850aa0dfb', NULL, '2025-11-20 00:32:04', 1),
(18, 3, 'email_token', NULL, '271215', '2025-11-19 16:50:26', 0),
(19, 3, 'email_token', NULL, '163363', '2025-11-19 23:53:41', 0),
(20, 3, 'email_token', NULL, '305976', '2025-11-19 23:53:48', 0),
(21, 3, 'email_token', NULL, '929938', '2025-11-19 23:54:38', 1),
(22, 3, 'phone_otp', NULL, '526824', '2025-11-19 23:45:15', 0),
(23, 3, 'phone_otp', NULL, '619149', '2025-11-19 23:45:22', 0),
(24, 3, 'phone_otp', NULL, '456386', '2025-11-19 23:51:32', 1),
(25, 3, 'email_token', NULL, '453184', '2025-11-20 00:01:56', 0);

-- --------------------------------------------------------

--
-- Table structure for table `production`
--

CREATE TABLE `production` (
  `production_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty_baked` int(11) NOT NULL,
  `date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `production`
--

INSERT INTO `production` (`production_id`, `product_id`, `qty_baked`, `date`) VALUES
(1, 5, 24, '2025-11-06'),
(2, 1, 10, '2025-11-06'),
(3, 28, 5, '2025-11-06'),
(4, 27, 1, '2025-11-07'),
(5, 31, 10, '2025-11-07'),
(6, 31, 5, '2025-11-07'),
(7, 31, 10, '2025-11-07'),
(8, 27, 10, '2025-11-07'),
(9, 28, 10, '2025-11-11'),
(10, 28, 2, '2025-11-17'),
(11, 8, 5, '2025-11-20'),
(12, 22, 2, '2025-11-21');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `product_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `status` enum('available','recalled','discontinued') NOT NULL DEFAULT 'available',
  `stock_qty` int(11) DEFAULT 0,
  `stock_unit` varchar(20) NOT NULL DEFAULT 'pcs',
  `is_sellable` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = Appears on POS, 0 = Intermediate product',
  `batch_size` int(11) NOT NULL DEFAULT 1 COMMENT 'Number of units produced per recipe'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`product_id`, `name`, `price`, `image_url`, `status`, `stock_qty`, `stock_unit`, `is_sellable`, `batch_size`) VALUES
(1, 'Spanish Bread', 10.00, NULL, 'available', 47, 'pcs', 1, 24),
(2, 'Cheese Bread', 12.00, '../uploads/products/prod_6912d9f7dd8542.44340855.jpg', 'available', 21, 'pcs', 1, 20),
(3, 'Ensaymada', 15.00, NULL, 'available', 25, 'pcs', 1, 12),
(4, 'Cinnamon Roll', 20.00, NULL, 'available', 21, 'pcs', 1, 12),
(5, 'Choco Bread', 12.00, '../uploads/products/prod_6912dc4b078fa3.44458814.jpg', 'available', 53, 'pcs', 1, 24),
(6, 'Ube Cheese Pandesal', 15.00, NULL, 'available', 35, 'pcs', 1, 30),
(7, 'Hotdog Roll', 18.00, NULL, 'available', 15, 'pcs', 1, 1),
(8, 'Cheese Stick Bread', 10.00, '../uploads/products/prod_6912da69d6bf68.30804241.jpg', 'available', 30, 'pcs', 1, 1),
(9, 'Tuna Bun', 20.00, NULL, 'available', 15, 'pcs', 1, 1),
(10, 'Egg Pie Slice', 25.00, NULL, 'available', 8, 'pcs', 1, 1),
(11, 'Mocha Bun', 12.00, NULL, 'available', 34, 'pcs', 1, 1),
(12, 'Corned Beef Bread', 20.00, NULL, 'available', 16, 'pcs', 1, 1),
(13, 'Chicken Floss Bun', 25.00, '../uploads/products/prod_6912dc2989ccb1.13733051.jpg', 'available', 34, 'pcs', 1, 1),
(14, 'Chocolate Donut', 18.00, NULL, 'available', 30, 'pcs', 1, 1),
(16, 'Cream Bread', 10.00, NULL, 'available', 48, 'pcs', 1, 1),
(17, 'Coffee Bun', 15.00, NULL, 'available', 29, 'pcs', 1, 1),
(18, 'Garlic Bread', 12.00, NULL, 'available', 30, 'pcs', 1, 1),
(19, 'Milky Loaf', 35.00, NULL, 'available', 19, 'loaf', 1, 1),
(20, 'Whole Wheat Loaf', 40.00, NULL, 'available', 14, 'loaf', 1, 1),
(21, 'Raisin Bread', 20.00, NULL, 'available', 15, 'pcs', 1, 1),
(22, 'Banana Loaf', 35.00, '../uploads/products/prod_6912da909c02d2.10638207.jpg', 'available', 2, 'loaf', 1, 1),
(23, 'Cheese Cupcake', 15.00, '../uploads/products/prod_6912da1b2c3e28.82878651.jpg', 'available', 19, 'pcs', 1, 1),
(24, 'Butter Muffin', 18.00, '../uploads/products/prod_6912d9d87feab5.21908923.jpg', 'available', 6, 'pcs', 1, 1),
(25, 'Yema Bread', 12.00, NULL, 'available', 30, 'pcs', 1, 1),
(26, 'Chocolate Crinkles', 10.00, '../uploads/products/prod_6912dce63c5352.95058142.jpg', 'available', 24, 'pcs', 1, 1),
(27, 'Pan de Coco', 12.00, NULL, 'available', 0, 'pcs', 1, 1),
(28, 'Baguette', 30.00, '../uploads/products/prod_6912d96d877162.80266620.jpg', 'available', 8, 'pcs', 1, 1),
(29, 'Focaccia Bread', 28.00, NULL, 'available', 5, 'pcs', 1, 1),
(30, 'Mini Donut', 8.00, NULL, 'available', 30, 'pcs', 1, 1),
(31, 'Pandesal', 2.00, '../uploads/products/prod_691b0d3c93d025.68910842.jpg', 'available', 15, 'pcs', 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `product_recalls`
--

CREATE TABLE `product_recalls` (
  `recall_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `recall_date` date DEFAULT NULL,
  `status` enum('active','completed') NOT NULL DEFAULT 'active',
  `affected_batch_date_start` date DEFAULT NULL,
  `affected_batch_date_end` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `recalled_stock_log`
--

CREATE TABLE `recalled_stock_log` (
  `log_id` int(11) NOT NULL,
  `recall_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `qty_removed` int(11) NOT NULL,
  `date_removed` datetime NOT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `recipes`
--

CREATE TABLE `recipes` (
  `recipe_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `ingredient_id` int(11) NOT NULL,
  `qty_needed` float NOT NULL,
  `unit` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `recipes`
--

INSERT INTO `recipes` (`recipe_id`, `product_id`, `ingredient_id`, `qty_needed`, `unit`) VALUES
(22, 1, 6, 1, 'kg'),
(23, 1, 8, 150, 'g'),
(24, 1, 11, 20, 'g'),
(25, 1, 13, 100, 'g'),
(26, 1, 14, 2, 'pcs'),
(27, 1, 15, 500, 'ml'),
(28, 2, 7, 800, 'g'),
(29, 2, 8, 100, 'g'),
(30, 2, 11, 15, 'g'),
(31, 2, 12, 100, 'g'),
(32, 2, 16, 400, 'ml'),
(33, 2, 19, 250, 'g'),
(34, 2, 14, 3, 'pcs'),
(35, 3, 6, 1, 'kg'),
(36, 3, 8, 200, 'g'),
(37, 3, 11, 20, 'g'),
(38, 3, 12, 250, 'g'),
(39, 3, 14, 6, 'pcs'),
(40, 3, 16, 300, 'ml'),
(41, 3, 19, 200, 'g'),
(42, 4, 7, 500, 'g'),
(43, 4, 8, 100, 'g'),
(44, 4, 11, 10, 'g'),
(45, 4, 12, 100, 'g'),
(46, 4, 14, 2, 'pcs'),
(47, 4, 16, 250, 'ml'),
(48, 4, 9, 150, 'g'),
(49, 4, 21, 25, 'g'),
(50, 5, 6, 1, 'kg'),
(51, 5, 8, 150, 'g'),
(52, 5, 11, 20, 'g'),
(53, 5, 12, 100, 'g'),
(54, 5, 14, 2, 'pcs'),
(55, 5, 16, 500, 'ml'),
(56, 5, 20, 300, 'g'),
(57, 6, 7, 1, 'kg'),
(58, 6, 8, 150, 'g'),
(59, 6, 11, 20, 'g'),
(60, 6, 12, 100, 'g'),
(61, 6, 16, 500, 'ml'),
(62, 6, 22, 500, 'g'),
(63, 6, 19, 300, 'g'),
(64, 7, 6, 500, 'g'),
(65, 7, 8, 50, 'g'),
(66, 7, 11, 10, 'g'),
(67, 7, 13, 50, 'g'),
(68, 7, 15, 250, 'ml'),
(69, 7, 23, 15, 'pcs'),
(70, 8, 7, 500, 'g'),
(71, 8, 8, 75, 'g'),
(72, 8, 11, 10, 'g'),
(73, 8, 13, 60, 'g'),
(74, 8, 16, 200, 'ml'),
(75, 8, 19, 200, 'g'),
(76, 9, 6, 500, 'g'),
(77, 9, 8, 50, 'g'),
(78, 9, 11, 10, 'g'),
(79, 9, 13, 50, 'g'),
(80, 9, 15, 250, 'ml'),
(81, 9, 24, 2, 'can'),
(82, 9, 14, 1, 'pcs'),
(83, 10, 7, 250, 'g'),
(84, 10, 8, 200, 'g'),
(85, 10, 13, 100, 'g'),
(86, 10, 14, 8, 'pcs'),
(87, 10, 17, 2, 'can'),
(88, 10, 18, 1, 'can'),
(89, 11, 6, 800, 'g'),
(90, 11, 8, 100, 'g'),
(91, 11, 11, 15, 'g'),
(92, 11, 12, 100, 'g'),
(93, 11, 16, 400, 'ml'),
(94, 11, 35, 20, 'g'),
(95, 11, 14, 2, 'pcs'),
(96, 12, 6, 500, 'g'),
(97, 12, 8, 50, 'g'),
(98, 12, 11, 10, 'g'),
(99, 12, 13, 50, 'g'),
(100, 12, 15, 250, 'ml'),
(101, 12, 26, 2, 'can'),
(102, 13, 6, 500, 'g'),
(103, 13, 8, 60, 'g'),
(104, 13, 11, 10, 'g'),
(105, 13, 12, 60, 'g'),
(106, 13, 16, 250, 'ml'),
(107, 13, 27, 200, 'g'),
(108, 13, 14, 2, 'pcs'),
(109, 14, 7, 500, 'g'),
(110, 14, 8, 100, 'g'),
(111, 14, 11, 10, 'g'),
(112, 14, 13, 50, 'g'),
(113, 14, 14, 2, 'pcs'),
(114, 14, 16, 200, 'ml'),
(115, 14, 29, 50, 'g'),
(116, 16, 7, 1, 'kg'),
(117, 16, 8, 150, 'g'),
(118, 16, 11, 20, 'g'),
(119, 16, 13, 100, 'g'),
(120, 16, 17, 1, 'can'),
(121, 16, 18, 1, 'can'),
(122, 17, 6, 800, 'g'),
(123, 17, 8, 100, 'g'),
(124, 17, 11, 15, 'g'),
(125, 17, 12, 100, 'g'),
(126, 17, 16, 400, 'ml'),
(127, 17, 35, 25, 'g'),
(128, 17, 14, 2, 'pcs'),
(129, 18, 7, 500, 'g'),
(130, 18, 11, 10, 'g'),
(131, 18, 12, 150, 'g'),
(132, 18, 15, 250, 'ml'),
(133, 18, 25, 50, 'g'),
(134, 18, 10, 10, 'g'),
(135, 19, 6, 1, 'kg'),
(136, 19, 8, 150, 'g'),
(137, 19, 11, 20, 'g'),
(138, 19, 12, 100, 'g'),
(139, 19, 16, 600, 'ml'),
(140, 19, 10, 15, 'g'),
(141, 20, 36, 1, 'kg'),
(142, 20, 9, 50, 'g'),
(143, 20, 11, 20, 'g'),
(144, 20, 37, 50, 'ml'),
(145, 20, 15, 600, 'ml'),
(146, 20, 10, 15, 'g'),
(147, 21, 7, 500, 'g'),
(148, 21, 8, 100, 'g'),
(149, 21, 11, 10, 'g'),
(150, 21, 12, 80, 'g'),
(151, 21, 16, 200, 'ml'),
(152, 21, 32, 150, 'g'),
(153, 21, 21, 5, 'g'),
(154, 22, 7, 500, 'g'),
(155, 22, 28, 1, 'kg'),
(156, 22, 8, 200, 'g'),
(157, 22, 12, 120, 'g'),
(158, 22, 14, 4, 'pcs'),
(159, 22, 34, 10, 'g'),
(160, 23, 7, 500, 'g'),
(161, 23, 8, 300, 'g'),
(162, 23, 12, 150, 'g'),
(163, 23, 14, 4, 'pcs'),
(164, 23, 16, 250, 'ml'),
(165, 23, 19, 200, 'g'),
(166, 23, 34, 15, 'g'),
(167, 24, 7, 500, 'g'),
(168, 24, 8, 300, 'g'),
(169, 24, 12, 200, 'g'),
(170, 24, 14, 4, 'pcs'),
(171, 24, 16, 250, 'ml'),
(172, 24, 34, 15, 'g'),
(173, 25, 6, 800, 'g'),
(174, 25, 8, 100, 'g'),
(175, 25, 11, 15, 'g'),
(176, 25, 13, 80, 'g'),
(177, 25, 16, 400, 'ml'),
(178, 25, 31, 300, 'g'),
(179, 26, 7, 300, 'g'),
(180, 26, 29, 100, 'g'),
(181, 26, 8, 250, 'g'),
(182, 26, 14, 3, 'pcs'),
(183, 26, 12, 80, 'g'),
(184, 26, 34, 10, 'g'),
(185, 27, 7, 1, 'kg'),
(186, 27, 8, 150, 'g'),
(187, 27, 11, 20, 'g'),
(188, 27, 13, 100, 'g'),
(189, 27, 15, 500, 'ml'),
(190, 27, 30, 250, 'g'),
(191, 27, 18, 1, 'can'),
(192, 28, 6, 1, 'kg'),
(193, 28, 11, 15, 'g'),
(194, 28, 10, 20, 'g'),
(195, 28, 15, 650, 'ml'),
(196, 29, 6, 1, 'kg'),
(197, 29, 11, 15, 'g'),
(198, 29, 10, 20, 'g'),
(199, 29, 15, 700, 'ml'),
(200, 29, 37, 100, 'ml'),
(201, 30, 7, 500, 'g'),
(202, 30, 8, 100, 'g'),
(203, 30, 11, 10, 'g'),
(204, 30, 13, 50, 'g'),
(205, 30, 14, 2, 'pcs'),
(206, 30, 16, 200, 'ml'),
(207, 28, 37, 2, 'ml'),
(208, 31, 7, 200, 'g');

-- --------------------------------------------------------

--
-- Table structure for table `returns`
--

CREATE TABLE `returns` (
  `return_id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL COMMENT 'FK to sales table',
  `product_id` int(11) NOT NULL COMMENT 'FK to products table',
  `user_id` int(11) DEFAULT NULL COMMENT 'FK to users table (who processed it)',
  `qty_returned` int(11) NOT NULL,
  `return_value` decimal(10,2) NOT NULL COMMENT 'Value of the items returned',
  `reason` varchar(255) DEFAULT NULL,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `returns`
--

INSERT INTO `returns` (`return_id`, `sale_id`, `product_id`, `user_id`, `qty_returned`, `return_value`, `reason`, `timestamp`) VALUES
(1, 53, 13, 3, 1, 25.00, 'Spoiled Item', '2025-11-07 19:33:28'),
(2, 52, 26, 3, 1, 10.00, 'Spoiled Item', '2025-11-07 19:44:20'),
(3, 51, 12, 3, 1, 20.00, 'Spoiled Item', '2025-11-07 21:22:39'),
(4, 89, 22, 2, 2, 70.00, 'Customer Refund', '2025-11-17 22:39:31');

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `qty_sold` int(11) DEFAULT NULL,
  `total_price` decimal(10,2) DEFAULT NULL,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `qty_returned` int(11) NOT NULL DEFAULT 0,
  `timestamp` datetime DEFAULT current_timestamp() COMMENT 'Was DATE, now DATETIME'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`sale_id`, `product_id`, `user_id`, `qty_sold`, `total_price`, `discount_percent`, `qty_returned`, `timestamp`) VALUES
(1, 7, 3, 2, 50.00, 0.00, 0, '2025-10-23 00:00:00'),
(2, 4, 3, 10, 50.00, 0.00, 0, '2025-10-23 00:00:00'),
(3, 4, 3, 5, 25.00, 0.00, 0, '2025-10-23 00:00:00'),
(4, 6, 3, 3, 75.00, 0.00, 0, '2025-10-23 00:00:00'),
(5, 4, 3, 5, 25.00, 0.00, 0, '2025-10-23 00:00:00'),
(6, 6, 3, 4, 100.00, 0.00, 0, '2025-10-23 00:00:00'),
(7, 7, 3, 5, 125.00, 0.00, 0, '2025-10-23 00:00:00'),
(8, 7, 3, 2, 50.00, 0.00, 0, '2025-10-23 00:00:00'),
(9, 6, 3, 1, 25.00, 0.00, 0, '2025-10-23 00:00:00'),
(10, 4, 3, 5, 25.00, 0.00, 0, '2025-10-23 00:00:00'),
(11, 7, 3, 4, 100.00, 0.00, 0, '2025-10-23 00:00:00'),
(12, 4, 3, 10, 50.00, 0.00, 0, '2025-10-23 00:00:00'),
(13, 6, 3, 2, 50.00, 0.00, 0, '2025-10-23 00:00:00'),
(14, 7, 3, 2, 50.00, 0.00, 0, '2025-10-23 00:00:00'),
(15, 4, 3, 10, 50.00, 0.00, 0, '2025-10-28 00:00:00'),
(16, 6, 3, 2, 50.00, 0.00, 0, '2025-10-28 00:00:00'),
(17, 7, 3, 2, 50.00, 0.00, 0, '2025-10-28 00:00:00'),
(18, 4, 3, 10, 50.00, 0.00, 0, '2025-10-28 00:00:00'),
(19, 4, 3, 5, 25.00, 0.00, 0, '2025-10-28 00:00:00'),
(20, 6, 3, 3, 75.00, 0.00, 0, '2025-10-28 00:00:00'),
(21, 7, 3, 2, 50.00, 0.00, 0, '2025-10-28 00:00:00'),
(22, 6, 3, 2, 50.00, 0.00, 0, '2025-10-28 00:00:00'),
(23, 7, 3, 2, 50.00, 0.00, 0, '2025-10-28 00:00:00'),
(24, 7, 3, 5, 125.00, 0.00, 0, '2025-10-28 00:00:00'),
(25, 7, 3, 2, 50.00, 0.00, 0, '2025-10-28 00:00:00'),
(26, 6, 3, 1, 25.00, 0.00, 0, '2025-10-28 00:00:00'),
(27, 4, 3, 10, 50.00, 0.00, 0, '2025-10-28 00:00:00'),
(28, 4, 3, 5, 25.00, 0.00, 0, '2025-10-28 00:00:00'),
(29, 6, 3, 2, 50.00, 0.00, 0, '2025-10-28 00:00:00'),
(30, 7, 3, 2, 50.00, 0.00, 0, '2025-10-28 00:00:00'),
(31, 4, 3, 10, 50.00, 0.00, 0, '2025-10-30 00:00:00'),
(32, 13, 3, 2, 50.00, 0.00, 0, '2025-10-30 00:00:00'),
(33, 10, 3, 2, 50.00, 0.00, 0, '2025-10-30 00:00:00'),
(34, 3, 3, 2, 30.00, 0.00, 0, '2025-10-30 00:00:00'),
(35, 7, 3, 5, 90.00, 0.00, 0, '2025-10-30 00:00:00'),
(36, 16, 3, 2, 20.00, 0.00, 0, '2025-10-30 00:00:00'),
(37, 19, 3, 1, 35.00, 0.00, 0, '2025-10-30 00:00:00'),
(38, 20, 3, 1, 40.00, 0.00, 0, '2025-10-30 00:00:00'),
(39, 6, 3, 5, 75.00, 0.00, 0, '2025-10-30 00:00:00'),
(40, 13, 3, 3, 75.00, 0.00, 0, '2025-11-04 00:00:00'),
(41, 22, 3, 5, 175.00, 0.00, 0, '2025-11-06 00:00:00'),
(42, 24, 3, 2, 36.00, 0.00, 0, '2025-11-06 00:00:00'),
(43, 24, 3, 3, 54.00, 0.00, 0, '2025-11-06 00:00:00'),
(44, 28, 3, 2, 60.00, 0.00, 0, '2025-11-07 00:00:00'),
(45, 28, 3, 3, 90.00, 0.00, 0, '2025-11-07 00:00:00'),
(46, 23, 3, 1, 15.00, 0.00, 0, '2025-11-07 00:00:00'),
(47, 26, 3, 1, 10.00, 0.00, 0, '2025-11-07 00:00:00'),
(48, 17, 3, 1, 15.00, 0.00, 0, '2025-11-07 00:00:00'),
(49, 1, 3, 3, 30.00, 0.00, 0, '2025-11-07 00:00:00'),
(50, 3, 3, 3, 45.00, 0.00, 0, '2025-11-07 00:00:00'),
(51, 12, 3, 1, 20.00, 0.00, 1, '2025-11-07 00:00:00'),
(52, 26, 3, 1, 10.00, 0.00, 1, '2025-11-07 00:00:00'),
(53, 13, 3, 1, 25.00, 0.00, 1, '2025-11-07 18:21:28'),
(54, 12, 3, 4, 68.00, 0.00, 0, '2025-11-11 13:15:41'),
(55, 5, 3, 1, 10.20, 0.00, 0, '2025-11-11 13:15:41'),
(56, 2, 3, 1, 9.60, 20.00, 0, '2025-11-11 15:08:40'),
(57, 24, 3, 1, 14.40, 20.00, 0, '2025-11-11 15:08:40'),
(58, 13, 3, 1, 20.00, 20.00, 0, '2025-11-11 15:08:40'),
(59, 8, 3, 1, 8.00, 20.00, 0, '2025-11-11 15:08:40'),
(60, 13, 3, 1, 25.00, 0.00, 0, '2025-11-11 15:17:24'),
(61, 8, 3, 1, 10.00, 0.00, 0, '2025-11-11 15:17:24'),
(62, 22, 3, 1, 35.00, 0.00, 0, '2025-11-11 15:17:24'),
(63, 2, 3, 1, 12.00, 0.00, 0, '2025-11-11 15:17:24'),
(64, 5, 3, 1, 12.00, 0.00, 0, '2025-11-11 15:17:24'),
(65, 2, 3, 1, 12.00, 0.00, 0, '2025-11-12 15:22:30'),
(66, 24, 3, 1, 18.00, 0.00, 0, '2025-11-12 15:22:30'),
(67, 13, 3, 1, 25.00, 0.00, 0, '2025-11-12 15:22:30'),
(68, 4, 3, 1, 20.00, 0.00, 0, '2025-11-12 15:22:30'),
(69, 24, 2, 1, 18.00, 0.00, 0, '2025-11-16 01:33:35'),
(70, 2, 2, 1, 12.00, 0.00, 0, '2025-11-16 01:33:35'),
(71, 5, 2, 1, 12.00, 0.00, 0, '2025-11-16 01:33:35'),
(72, 13, 2, 1, 25.00, 0.00, 0, '2025-11-16 01:33:35'),
(73, 28, 2, 1, 27.00, 10.00, 0, '2025-11-16 01:47:34'),
(74, 22, 2, 1, 31.50, 10.00, 0, '2025-11-16 01:47:34'),
(75, 8, 2, 1, 10.00, 0.00, 0, '2025-11-16 02:15:09'),
(76, 23, 2, 1, 15.00, 0.00, 0, '2025-11-16 02:15:09'),
(77, 13, 2, 1, 25.00, 0.00, 0, '2025-11-16 02:15:09'),
(78, 4, 2, 3, 60.00, 0.00, 0, '2025-11-16 02:15:09'),
(79, 11, 2, 1, 12.00, 0.00, 0, '2025-11-16 02:18:54'),
(80, 13, 1, 1, 22.50, 10.00, 0, '2025-11-16 02:28:59'),
(81, 5, 1, 1, 10.80, 10.00, 0, '2025-11-16 02:28:59'),
(82, 2, 1, 3, 32.40, 10.00, 0, '2025-11-16 02:28:59'),
(83, 8, 1, 2, 18.00, 10.00, 0, '2025-11-16 02:28:59'),
(84, 23, 1, 1, 13.50, 10.00, 0, '2025-11-16 02:28:59'),
(85, 22, 2, 1, 35.00, 0.00, 0, '2025-11-16 13:42:21'),
(86, 28, 2, 1, 30.00, 0.00, 0, '2025-11-16 13:42:21'),
(87, 23, 2, 2, 30.00, 0.00, 0, '2025-11-16 13:42:21'),
(88, 24, 3, 3, 54.00, 0.00, 0, '2025-11-17 21:02:47'),
(89, 22, 3, 2, 70.00, 0.00, 2, '2025-11-17 21:02:47'),
(90, 28, 3, 1, 30.00, 0.00, 0, '2025-11-18 18:36:11'),
(91, 24, 3, 1, 18.00, 0.00, 0, '2025-11-18 18:36:11'),
(92, 2, 3, 1, 12.00, 0.00, 0, '2025-11-18 18:36:11'),
(93, 23, 3, 1, 15.00, 0.00, 0, '2025-11-18 18:36:11'),
(94, 26, 3, 1, 10.00, 0.00, 0, '2025-11-18 18:36:11'),
(95, 5, 3, 1, 12.00, 0.00, 0, '2025-11-18 18:36:11'),
(96, 13, 3, 1, 25.00, 0.00, 0, '2025-11-18 18:36:11'),
(97, 28, 2, 1, 30.00, 0.00, 0, '2025-11-19 20:28:53'),
(98, 24, 3, 1, 18.00, 0.00, 0, '2025-11-19 22:03:49'),
(99, 2, 3, 1, 12.00, 0.00, 0, '2025-11-19 22:03:49'),
(100, 5, 3, 1, 12.00, 0.00, 0, '2025-11-19 22:03:49');

-- --------------------------------------------------------

--
-- Table structure for table `stock_adjustments`
--

CREATE TABLE `stock_adjustments` (
  `adjustment_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `item_type` enum('product','ingredient') NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `adjustment_qty` float NOT NULL COMMENT 'Can be positive (add) or negative (remove)',
  `reason` varchar(255) DEFAULT NULL,
  `timestamp` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_adjustments`
--

INSERT INTO `stock_adjustments` (`adjustment_id`, `item_id`, `item_type`, `user_id`, `adjustment_qty`, `reason`, `timestamp`) VALUES
(1, 4, 'product', 3, 5, 'New', '2025-10-23 20:45:44'),
(2, 4, 'product', 3, 30, 'Newly Baked', '2025-10-23 20:47:09'),
(3, 6, 'product', 3, 10, 'Newly Baked', '2025-10-23 20:47:23'),
(4, 7, 'product', 3, 15, 'Newly Baked', '2025-10-23 20:47:30'),
(5, 7, 'product', 3, 15, 'Newly Baked', '2025-10-23 21:33:31'),
(6, 6, 'product', 3, 10, 'Newly Baked', '2025-10-23 21:33:37'),
(7, 4, 'product', 3, 30, 'Newly Baked', '2025-10-23 21:33:46'),
(8, 4, 'product', 3, 20, 'Newly Baked', '2025-10-28 23:35:31'),
(9, 4, 'product', 3, -10, 'recall Spoilage', '2025-10-28 23:35:43'),
(10, 7, 'product', 3, 10, 'Newly Baked', '2025-10-29 00:36:19'),
(11, 4, 'product', 3, 30, 'Newly Baked', '2025-10-29 00:36:28'),
(12, 6, 'product', 3, 10, 'Newly Baked', '2025-10-29 00:36:35'),
(13, 7, 'product', 3, 5, 'Newly Baked', '2025-10-29 00:59:48'),
(14, 0, 'product', 3, 10, 'Newly Baked', '2025-10-30 22:05:11'),
(15, 0, 'product', 3, -25, 'Correction', '2025-10-30 22:11:20'),
(16, 0, 'product', 3, 25, 'Correction', '2025-10-30 22:12:00'),
(17, 2, 'product', 3, -10, 'Correction', '2025-10-30 22:19:19'),
(18, 17, 'product', 3, 5, 'Newly Baked', '2025-10-30 22:19:37'),
(19, 13, 'product', 3, 20, 'Newly Baked', '2025-11-04 08:07:17'),
(20, 0, 'ingredient', 3, 5, 'Restock', '2025-11-04 08:44:41'),
(21, 0, 'ingredient', 3, 5, 'Restock', '2025-11-04 08:44:57'),
(22, 0, 'ingredient', 3, 5, 'Restock', '2025-11-04 08:51:02'),
(23, 0, 'ingredient', 3, 5, 'Restock', '2025-11-04 08:51:06'),
(24, 0, 'ingredient', 3, 5, 'Restock', '2025-11-04 08:51:16'),
(25, 0, 'ingredient', 3, 5, 'Restock', '2025-11-04 08:51:30'),
(26, 0, 'ingredient', 3, 5, 'Restock', '2025-11-04 08:52:01'),
(27, 0, 'ingredient', 3, 5, 'Restock', '2025-11-04 08:53:02'),
(28, 0, 'ingredient', 3, 5, 'Restock', '2025-11-04 08:53:04'),
(29, 0, 'ingredient', 3, 5, 'Restock', '2025-11-04 08:53:13'),
(30, 0, 'ingredient', 3, 5, 'Restock', '2025-11-04 08:53:17'),
(31, 0, 'ingredient', 3, 5, 'Restock', '2025-11-04 08:53:35'),
(32, 0, 'ingredient', 3, 5, 'Restock', '2025-11-04 08:53:38'),
(33, 27, 'product', 3, 5, 'Newly Baked', '2025-11-04 08:55:44'),
(34, 0, 'ingredient', 3, 5, 'Restock', '2025-11-04 08:59:10'),
(35, 0, 'ingredient', 3, 5, 'Restock', '2025-11-04 09:01:47'),
(36, 0, 'ingredient', 3, 5, 'Restock', '2025-11-04 09:05:50'),
(37, 0, 'ingredient', 3, 5, 'Restock', '2025-11-04 09:06:36'),
(38, 27, 'product', 3, 5, 'Newly Baked', '2025-11-04 09:06:47'),
(39, 27, 'product', 3, 5, 'recall Spoilage', '2025-11-04 09:09:04'),
(40, 27, 'product', 3, -5, 'recall Spoilage', '2025-11-04 09:09:25'),
(41, 0, 'ingredient', 3, 5, 'Restock', '2025-11-04 09:11:15'),
(42, 0, 'ingredient', 3, 5, 'Restock', '2025-11-04 09:15:07'),
(43, 0, 'ingredient', 3, 5, 'Restock', '2025-11-04 09:16:13'),
(44, 5, 'ingredient', 3, 3, 'Restock', '2025-11-04 09:28:36'),
(45, 27, 'product', 3, -10, 'recall Spoilage', '2025-11-04 09:43:34'),
(46, 27, 'product', 3, -5, 'recall Spoilage', '2025-11-05 23:24:43'),
(47, 28, 'product', 3, -10, 'recall Spoilage', '2025-11-05 23:58:01'),
(48, 27, 'product', 3, -5, 'Correction', '2025-11-06 09:54:04'),
(49, 5, 'product', 3, 24, 'Newly Baked', '2025-11-06 14:50:24'),
(50, 1, 'product', 3, 10, 'Newly Baked', '2025-11-06 14:51:53'),
(51, 1, 'product', 3, -10, 'Correction', '2025-11-06 14:52:19'),
(52, 28, 'product', 3, 5, 'Newly Baked', '2025-11-06 15:02:50'),
(53, 27, 'product', 3, 1, 'Newly Baked', '2025-11-07 11:08:37'),
(54, 27, 'product', 3, -11, 'recall Spoilage', '2025-11-07 11:14:22'),
(55, 31, 'product', 3, 10, 'Newly Baked', '2025-11-07 13:36:44'),
(56, 13, 'product', 3, 1, 'Return (SaleID: 53): Spoiled Item', '2025-11-07 18:57:56'),
(57, 26, 'product', 3, 1, 'Return (SaleID: 52): Spoilage', '2025-11-07 19:00:51'),
(58, 31, 'product', 3, 5, '[Recall] trial', '2025-11-07 20:01:04'),
(59, 31, 'product', 3, 10, '[Production] Newly Baked', '2025-11-07 20:02:27'),
(60, 27, 'product', 3, 10, '[Production] Newly Baked', '2025-11-07 20:03:14'),
(61, 27, 'product', 3, -5, '[Correction] Correction', '2025-11-07 20:03:41'),
(62, 27, 'product', 3, -5, '[Spoilage] Spoiled', '2025-11-07 20:04:00'),
(63, 31, 'product', 3, -10, '[Recall] For Crumbing', '2025-11-07 20:04:36'),
(64, 30, 'ingredient', 3, 20, 'Restock', '2025-11-07 20:13:19'),
(65, 23, 'ingredient', 3, -20, '[Correction] Typo', '2025-11-07 20:22:31'),
(66, 28, 'product', 3, 10, '[Production] Newly Baked', '2025-11-11 14:37:12'),
(67, 22, 'product', 3, -2, '[Spoilage] Spoiled', '2025-11-17 22:52:53'),
(69, 24, 'product', 3, -1, '[Recall] Spoiled', '2025-11-17 23:33:20'),
(70, 7, 'ingredient', 3, 1, '[Restock] Random buy', '2025-11-20 22:14:06'),
(71, 8, 'product', 3, 5, '[Production] Newly Baked', '2025-11-20 22:14:34'),
(72, 7, 'ingredient', 3, 1, '[Correction] trial', '2025-11-20 22:18:06'),
(73, 7, 'ingredient', 3, -1, '[Correction] Correction', '2025-11-20 22:18:53'),
(74, 7, 'ingredient', 3, -1, '[Delete] Manual batch deletion', '2025-11-20 22:46:27'),
(75, 7, 'ingredient', 3, -1, '[Delete] Manual batch deletion', '2025-11-20 22:47:00'),
(76, 7, 'ingredient', 3, 1, '[Restock] trial', '2025-11-20 22:49:03'),
(77, 7, 'ingredient', 3, -1, '[Delete] Manual batch deletion', '2025-11-20 22:49:42'),
(78, 7, 'ingredient', 3, 1, '[Correction] +1', '2025-11-20 23:21:11'),
(79, 7, 'ingredient', 3, 1, '[Correction] +1', '2025-11-21 00:14:50'),
(80, 7, 'ingredient', 3, -1, '[Correction] -1', '2025-11-21 00:18:35'),
(81, 7, 'ingredient', 3, -1, '[Correction] -1', '2025-11-21 00:27:44'),
(82, 7, 'ingredient', 3, 1, '[Correction] +1', '2025-11-21 00:31:09'),
(83, 7, 'ingredient', 3, -1, '[Correction] -1', '2025-11-21 00:31:39'),
(84, 7, 'ingredient', 3, 1, '[Correction] +1', '2025-11-21 00:35:20'),
(85, 7, 'ingredient', 3, -1, '[Correction] -1', '2025-11-21 00:35:27'),
(86, 28, 'product', 3, -1, '[Recall] Newly Baked (Undone)', '2025-11-21 01:27:34'),
(87, 28, 'product', 3, 1, '[Undo Recall] Reversing Adj #86', '2025-11-21 01:40:25'),
(88, 22, 'product', 3, 2, '[Production] Newly Baked', '2025-11-21 02:16:17'),
(89, 28, 'ingredient', 3, 10, '[Restock] Newly bought', '2025-11-21 02:34:21'),
(90, 11, 'ingredient', 3, 1000, '[Restock] Newly bought', '2025-11-21 02:35:35'),
(91, 12, 'ingredient', 3, -8, '[Correction] -8', '2025-11-21 02:40:31'),
(92, 12, 'ingredient', 4, 8, '[Correction] +8', '2025-11-21 02:46:28');

-- --------------------------------------------------------

--
-- Table structure for table `unit_conversions`
--

CREATE TABLE `unit_conversions` (
  `unit` varchar(20) NOT NULL,
  `base_unit` enum('g','ml','pcs') NOT NULL,
  `to_base_factor` float NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `unit_conversions`
--

INSERT INTO `unit_conversions` (`unit`, `base_unit`, `to_base_factor`) VALUES
('bottle', 'pcs', 1),
('can', 'pcs', 1),
('g', 'g', 1),
('kg', 'g', 1000),
('L', 'ml', 1000),
('ml', 'ml', 1),
('pack', 'pcs', 1),
('pcs', 'pcs', 1),
('tray', 'pcs', 30);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('manager','cashier','assistant_manager') NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone_number` varchar(11) NOT NULL,
  `enable_daily_report` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'For daily SMS reports',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password`, `role`, `email`, `phone_number`, `enable_daily_report`, `created_at`) VALUES
(2, 'klain123', '$2y$10$pS2IpgUKXqAaSGO3oqgSHOnVJ0CS3FHy6f0nrDxFj6iapGe3FeTne', 'cashier', 'dreedklaingonito@gmail.com', '09923142756', 0, '2025-10-20 04:44:55'),
(3, 'gian123', '$2y$10$8HOAYekmrM7JYIj0oVukFe7.m8ah1dskJReruqVsElJnxr8orCA4u', 'manager', 'givano550@gmail.com', '09945005100', 0, '2025-10-20 05:50:57'),
(4, 'camile123', '$2y$10$DGybQGSq6fDZ2hW0P6UjpuVJUpmx2NuiXJFdm/6okJpMXvWGT7q/m', 'assistant_manager', NULL, '09935581868', 0, '2025-11-19 16:39:05'),
(5, 'cashier1', '$2y$10$b2EoxXyd.YoSko0RyKkcn.zSevyncBmxVbEo0IZK2Y9/qH1YjU2OO', 'cashier', NULL, '09359840820', 0, '2025-11-19 18:05:26'),
(6, 'asstntmngr1', '$2y$10$Y.4anQBnSUyBarvYmRfZROCnfph70nJTmRnLicyHoUk8Upp4NBpo.', 'cashier', NULL, '09123456789', 0, '2025-11-19 18:18:48');

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_activelowstockalerts`
-- (See below for the actual view)
--
CREATE TABLE `view_activelowstockalerts` (
`alert_id` int(11)
,`ingredient_id` int(11)
,`ingredient_name` varchar(100)
,`current_stock` float
,`reorder_level` float
,`message` text
,`date_triggered` date
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_discontinuedproducts`
-- (See below for the actual view)
--
CREATE TABLE `view_discontinuedproducts` (
`product_id` int(11)
,`name` varchar(100)
,`price` decimal(10,2)
,`stock_qty` int(11)
,`status` enum('available','recalled','discontinued')
,`stock_unit` varchar(20)
,`is_sellable` tinyint(1)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_ingredientstocklevel`
-- (See below for the actual view)
--
CREATE TABLE `view_ingredientstocklevel` (
`ingredient_id` int(11)
,`name` varchar(100)
,`unit` varchar(50)
,`stock_qty` double
,`reorder_level` float
,`stock_surplus` double
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_productinventory`
-- (See below for the actual view)
--
CREATE TABLE `view_productinventory` (
`product_id` int(11)
,`name` varchar(100)
,`price` decimal(10,2)
,`image_url` varchar(255)
,`stock_qty` int(11)
,`status` enum('available','recalled','discontinued')
,`stock_unit` varchar(20)
,`is_sellable` tinyint(1)
);

-- --------------------------------------------------------

--
-- Structure for view `view_activelowstockalerts`
--
DROP TABLE IF EXISTS `view_activelowstockalerts`;

CREATE ALGORITHM=UNDEFINED  SQL SECURITY DEFINER VIEW `view_activelowstockalerts`  AS SELECT `a`.`alert_id` AS `alert_id`, `a`.`ingredient_id` AS `ingredient_id`, `i`.`name` AS `ingredient_name`, `i`.`stock_qty` AS `current_stock`, `i`.`reorder_level` AS `reorder_level`, `a`.`message` AS `message`, `a`.`date_triggered` AS `date_triggered` FROM (`alerts` `a` join `ingredients` `i` on(`a`.`ingredient_id` = `i`.`ingredient_id`)) WHERE `a`.`status` = 'unread' ;

-- --------------------------------------------------------

--
-- Structure for view `view_discontinuedproducts`
--
DROP TABLE IF EXISTS `view_discontinuedproducts`;

CREATE ALGORITHM=UNDEFINED  SQL SECURITY DEFINER VIEW `view_discontinuedproducts`  AS SELECT `products`.`product_id` AS `product_id`, `products`.`name` AS `name`, `products`.`price` AS `price`, `products`.`stock_qty` AS `stock_qty`, `products`.`status` AS `status`, `products`.`stock_unit` AS `stock_unit`, `products`.`is_sellable` AS `is_sellable` FROM `products` WHERE `products`.`status` = 'discontinued' ORDER BY `products`.`name` ASC ;

-- --------------------------------------------------------

--
-- Structure for view `view_ingredientstocklevel`
--
DROP TABLE IF EXISTS `view_ingredientstocklevel`;

CREATE ALGORITHM=UNDEFINED  SQL SECURITY DEFINER VIEW `view_ingredientstocklevel`  AS SELECT `i`.`ingredient_id` AS `ingredient_id`, `i`.`name` AS `name`, `i`.`unit` AS `unit`, coalesce(sum(`ib`.`quantity`),0) AS `stock_qty`, `i`.`reorder_level` AS `reorder_level`, coalesce(sum(`ib`.`quantity`),0) - `i`.`reorder_level` AS `stock_surplus` FROM (`ingredients` `i` left join `ingredient_batches` `ib` on(`i`.`ingredient_id` = `ib`.`ingredient_id`)) GROUP BY `i`.`ingredient_id` ;

-- --------------------------------------------------------

--
-- Structure for view `view_productinventory`
--
DROP TABLE IF EXISTS `view_productinventory`;

CREATE ALGORITHM=UNDEFINED  SQL SECURITY DEFINER VIEW `view_productinventory`  AS SELECT `p`.`product_id` AS `product_id`, `p`.`name` AS `name`, `p`.`price` AS `price`, `p`.`image_url` AS `image_url`, `p`.`stock_qty` AS `stock_qty`, `p`.`status` AS `status`, `p`.`stock_unit` AS `stock_unit`, `p`.`is_sellable` AS `is_sellable` FROM `products` AS `p` WHERE `p`.`status` in ('available','recalled') ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `alerts`
--
ALTER TABLE `alerts`
  ADD PRIMARY KEY (`alert_id`),
  ADD KEY `ingredient_id` (`ingredient_id`);

--
-- Indexes for table `ingredients`
--
ALTER TABLE `ingredients`
  ADD PRIMARY KEY (`ingredient_id`);

--
-- Indexes for table `ingredient_batches`
--
ALTER TABLE `ingredient_batches`
  ADD PRIMARY KEY (`batch_id`),
  ADD KEY `ingredient_id` (`ingredient_id`),
  ADD KEY `expiration_date` (`expiration_date`);

--
-- Indexes for table `login_history`
--
ALTER TABLE `login_history`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `user_id_idx` (`user_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`reset_id`);

--
-- Indexes for table `production`
--
ALTER TABLE `production`
  ADD PRIMARY KEY (`production_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`product_id`);

--
-- Indexes for table `product_recalls`
--
ALTER TABLE `product_recalls`
  ADD PRIMARY KEY (`recall_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `recalled_stock_log`
--
ALTER TABLE `recalled_stock_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `recall_id` (`recall_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `recipes`
--
ALTER TABLE `recipes`
  ADD PRIMARY KEY (`recipe_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `ingredient_id` (`ingredient_id`);

--
-- Indexes for table `returns`
--
ALTER TABLE `returns`
  ADD PRIMARY KEY (`return_id`),
  ADD KEY `sale_id_idx` (`sale_id`),
  ADD KEY `product_id_idx` (`product_id`),
  ADD KEY `user_id_idx` (`user_id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`sale_id`);

--
-- Indexes for table `stock_adjustments`
--
ALTER TABLE `stock_adjustments`
  ADD PRIMARY KEY (`adjustment_id`),
  ADD KEY `user_id_idx` (`user_id`);

--
-- Indexes for table `unit_conversions`
--
ALTER TABLE `unit_conversions`
  ADD PRIMARY KEY (`unit`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `phone_number` (`phone_number`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `alerts`
--
ALTER TABLE `alerts`
  MODIFY `alert_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `ingredients`
--
ALTER TABLE `ingredients`
  MODIFY `ingredient_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `ingredient_batches`
--
ALTER TABLE `ingredient_batches`
  MODIFY `batch_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=69;

--
-- AUTO_INCREMENT for table `login_history`
--
ALTER TABLE `login_history`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=75;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `reset_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `production`
--
ALTER TABLE `production`
  MODIFY `production_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `product_recalls`
--
ALTER TABLE `product_recalls`
  MODIFY `recall_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `recalled_stock_log`
--
ALTER TABLE `recalled_stock_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `recipes`
--
ALTER TABLE `recipes`
  MODIFY `recipe_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=209;

--
-- AUTO_INCREMENT for table `returns`
--
ALTER TABLE `returns`
  MODIFY `return_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `sale_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=101;

--
-- AUTO_INCREMENT for table `stock_adjustments`
--
ALTER TABLE `stock_adjustments`
  MODIFY `adjustment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=93;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `login_history`
--
ALTER TABLE `login_history`
  ADD CONSTRAINT `fk_login_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `stock_adjustments`
--
ALTER TABLE `stock_adjustments`
  ADD CONSTRAINT `fk_stock_adjustments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
