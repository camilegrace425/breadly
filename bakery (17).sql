-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 25, 2025 at 01:58 PM
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
CREATE PROCEDURE `AdminDeleteUser` (IN `p_user_id` INT)   BEGIN
    DELETE FROM users WHERE user_id = p_user_id;
END$$

CREATE PROCEDURE `AdminGetAllUsers` ()   BEGIN
    SELECT user_id, username, role, email, phone_number, created_at 
    FROM users 
    ORDER BY role, username;
END$$

CREATE PROCEDURE `AdminGetManagers` ()   BEGIN
    SELECT user_id, username, phone_number
    FROM users
    WHERE role IN ('manager', 'assistant_manager') 
      AND phone_number IS NOT NULL 
      AND phone_number != '';
END$$

CREATE PROCEDURE `AdminGetMySettings` (IN `p_user_id` INT)   BEGIN
    SELECT phone_number, enable_daily_report 
    FROM users 
    WHERE user_id = p_user_id;
END$$

CREATE PROCEDURE `AdminGetUsersForDailyReport` ()   BEGIN
    SELECT phone_number 
    FROM users 
    WHERE 
        role = 'manager' 
        AND enable_daily_report = 1
        AND phone_number IS NOT NULL 
        AND phone_number != '';
END$$

CREATE PROCEDURE `AdminUpdateMySettings` (IN `p_user_id` INT, IN `p_phone_number` VARCHAR(12), IN `p_enable_report` TINYINT)   BEGIN
    UPDATE users
    SET 
        phone_number = p_phone_number,
        enable_daily_report = p_enable_report
    WHERE user_id = p_user_id;
END$$

CREATE PROCEDURE `AdminUpdateUser` (IN `p_user_id` INT, IN `p_username` VARCHAR(100), IN `p_password` VARCHAR(255), IN `p_role` ENUM('manager','cashier','assistant_manager'), IN `p_email` VARCHAR(150), IN `p_phone` VARCHAR(11))   BEGIN
    UPDATE users
    SET 
        username = p_username,
        password = IF(p_password IS NOT NULL AND p_password != '', p_password, password),
        role = p_role,
        email = p_email,
        phone_number = p_phone
    WHERE user_id = p_user_id;
END$$

CREATE PROCEDURE `DashboardGetActiveLowStockAlerts` (IN `p_limit` INT)   BEGIN
    SELECT * FROM view_ActiveLowStockAlerts
    ORDER BY current_stock ASC
    LIMIT p_limit;
END$$

CREATE PROCEDURE `DashboardGetLowStockAlertsCount` ()   BEGIN
    SELECT COUNT(*) AS alertCount FROM view_ActiveLowStockAlerts;
END$$

CREATE PROCEDURE `DashboardGetRecalledStockValue` (IN `p_date_start` DATE, IN `p_date_end` DATE)   BEGIN
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

CREATE PROCEDURE `DashboardGetSalesSummaryByDateRange` (IN `p_date_start` DATE, IN `p_date_end` DATE)   BEGIN
    SELECT
        COUNT(s.sale_id) AS totalSales,
        SUM(s.total_price) AS totalRevenue
    FROM
        sales s
    JOIN 
        orders o ON s.order_id = o.order_id
    WHERE
        DATE(o.timestamp) BETWEEN p_date_start AND p_date_end;
END$$

CREATE PROCEDURE `IngredientAdd` (IN `name` VARCHAR(100), IN `unit` VARCHAR(50), IN `stock_qty` FLOAT, IN `reorder_level` FLOAT)   BEGIN
    INSERT INTO ingredients(name, unit, stock_qty, reorder_level)
    VALUES (name, unit, stock_qty, reorder_level);

    SELECT LAST_INSERT_ID() AS new_ingredient_id;
END$$

CREATE PROCEDURE `IngredientAdjustStock` (IN `p_ingredient_id` INT, IN `p_user_id` INT, IN `p_adjustment_qty` FLOAT, IN `p_reason` VARCHAR(255), IN `p_expiration_date` DATE)   BEGIN
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

CREATE PROCEDURE `IngredientCheckLowStock` ()   BEGIN
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

CREATE PROCEDURE `IngredientDelete` (IN `p_ingredient_id` INT, OUT `p_status` VARCHAR(255))   BEGIN
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

CREATE PROCEDURE `IngredientGetAllSimple` ()   BEGIN
    SELECT ingredient_id, name, unit 
    FROM ingredients 
    ORDER BY name;
END$$

CREATE PROCEDURE `IngredientReduceStockBatchFEFO` (IN `p_ingredient_id` INT, IN `p_qty_to_remove` FLOAT)   BEGIN
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

CREATE PROCEDURE `IngredientUpdate` (IN `p_ingredient_id` INT, IN `p_name` VARCHAR(100), IN `p_unit` VARCHAR(50), IN `p_reorder_level` FLOAT)   BEGIN
    UPDATE ingredients
    SET
        name = p_name,
        unit = p_unit,
        reorder_level = p_reorder_level
    WHERE ingredient_id = p_ingredient_id;
END$$

CREATE PROCEDURE `InventoryGetDiscontinued` ()   BEGIN
    SELECT * FROM view_DiscontinuedProducts ORDER BY name;
END$$

CREATE PROCEDURE `InventoryGetIngredients` ()   BEGIN
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

CREATE PROCEDURE `InventoryGetProducts` ()   BEGIN
    SELECT * FROM view_ProductInventory ORDER BY name;
END$$

CREATE PROCEDURE `InventoryGetRecallHistory` (IN `p_date_start` DATE, IN `p_date_end` DATE)   BEGIN
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

CREATE PROCEDURE `LogLoginAttempt` (IN `p_user_id` INT, IN `p_username` VARCHAR(100), IN `p_status` ENUM('success','failure'), IN `p_device_type` VARCHAR(50))   BEGIN
    INSERT INTO login_history (user_id, username_attempt, status, device_type)
    VALUES (p_user_id, p_username, p_status, p_device_type);
END$$

CREATE PROCEDURE `PosGetAvailableProducts` ()   BEGIN
    SELECT product_id, name, price, stock_qty, image_url -- <-- ADDED image_url
    FROM view_ProductInventory
    WHERE status = 'available' AND stock_qty > 0
    ORDER BY name;
END$$

CREATE PROCEDURE `ProductAdd` (IN `p_name` VARCHAR(100), IN `p_price` DECIMAL(10,2), IN `p_image_url` VARCHAR(255))   BEGIN
    INSERT INTO products(name, price, image_url, stock_qty, status) -- <-- ADDED image_url
    VALUES (p_name, p_price, p_image_url, 0, 'available'); -- <-- ADDED p_image_url

    SELECT LAST_INSERT_ID() AS new_product_id;
END$$

CREATE PROCEDURE `ProductAdjustStock` (IN `p_product_id` INT, IN `p_user_id` INT, IN `p_adjustment_qty` INT, IN `p_reason` VARCHAR(255), OUT `p_status` VARCHAR(255))   BEGIN
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

CREATE PROCEDURE `ProductDelete` (IN `p_product_id` INT, OUT `p_status` VARCHAR(255))   BEGIN
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

CREATE PROCEDURE `ProductGetAllSimple` ()   BEGIN
    SELECT product_id, name, batch_size
    FROM products 
    WHERE status = 'available' 
    ORDER BY name;
END$$

CREATE PROCEDURE `ProductGetById` (IN `p_product_id` INT)   BEGIN
    SELECT * FROM products WHERE product_id = p_product_id;
END$$

CREATE PROCEDURE `ProductUpdate` (IN `p_product_id` INT, IN `p_name` VARCHAR(100), IN `p_price` DECIMAL(10,2), IN `p_status` ENUM('available','recalled','discontinued'), IN `p_image_url` VARCHAR(255))   BEGIN
    UPDATE products
    SET
        name = p_name,
        price = p_price,
        status = p_status,
        -- If p_image_url is NULL, keep the old one. If it's a path, update it.
        image_url = IF(p_image_url IS NULL, image_url, p_image_url) 
    WHERE product_id = p_product_id;
END$$

CREATE PROCEDURE `ProductUpdateBatchSize` (IN `p_product_id` INT, IN `p_batch_size` INT)   BEGIN
    UPDATE products
    SET batch_size = p_batch_size
    WHERE product_id = p_product_id;
END$$

CREATE PROCEDURE `RecallInitiate` (IN `product_id` INT, IN `reason` TEXT, IN `batch_start_date` DATE, IN `batch_end_date` DATE)   BEGIN
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

CREATE PROCEDURE `RecallLogRemoval` (IN `recall_id` INT, IN `user_id` INT, IN `qty_removed_from_stock` INT, IN `notes` TEXT)   BEGIN
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

CREATE PROCEDURE `RecipeAddIngredient` (IN `p_product_id` INT, IN `p_ingredient_id` INT, IN `p_qty_needed` FLOAT, IN `p_unit` VARCHAR(50))   BEGIN
    -- Check for duplicates first
    IF NOT EXISTS (SELECT 1 FROM recipes WHERE product_id = p_product_id AND ingredient_id = p_ingredient_id) THEN
        INSERT INTO recipes (product_id, ingredient_id, qty_needed, unit)
        VALUES (p_product_id, p_ingredient_id, p_qty_needed, p_unit);
    END IF;
END$$

CREATE PROCEDURE `RecipeGetByProductId` (IN `p_product_id` INT)   BEGIN
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

CREATE PROCEDURE `RecipeRemoveIngredient` (IN `p_recipe_id` INT)   BEGIN
    DELETE FROM recipes WHERE recipe_id = p_recipe_id;
END$$

CREATE PROCEDURE `ReportGetBestSellers` (IN `date_start` DATE, IN `date_end` DATE)   BEGIN
    SELECT
        p.name,
        SUM(s.qty_sold) AS total_units_sold,
        SUM(s.total_price) AS total_revenue
    FROM sales s
    JOIN orders o ON s.order_id = o.order_id -- Join Orders
    JOIN products p ON s.product_id = p.product_id
    WHERE DATE(o.timestamp) BETWEEN date_start AND date_end -- Filter Order Date
    GROUP BY p.product_id, p.name
    ORDER BY total_units_sold DESC;
END$$

CREATE PROCEDURE `ReportGetLoginHistory` ()   BEGIN
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

CREATE PROCEDURE `ReportGetReturnHistory` ()   BEGIN
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

CREATE PROCEDURE `ReportGetSalesHistory` (IN `p_date_start` DATE, IN `p_date_end` DATE, IN `p_sort_column` VARCHAR(50), IN `p_sort_direction` VARCHAR(4))   BEGIN
    SET @order_dir_asc = (UPPER(p_sort_direction) = 'ASC');

    SELECT
        s.order_id, 
        s.sale_id,
        o.timestamp AS date,  -- Get Date from Orders
        p.name AS product_name,
        s.qty_sold,
        (p.price * s.qty_sold) AS subtotal, 
        (p.price * s.qty_sold) - s.total_price AS discount_amount,
        s.total_price, 
        s.discount_percent, 
        s.qty_returned, 
        u.username AS cashier_username -- Get User from Orders->User
    FROM
        sales s
    JOIN
        orders o ON s.order_id = o.order_id -- The crucial JOIN
    LEFT JOIN
        products p ON s.product_id = p.product_id
    LEFT JOIN
        users u ON o.user_id = u.user_id
    WHERE
        DATE(o.timestamp) BETWEEN p_date_start AND p_date_end
    ORDER BY
        s.order_id DESC, 
        CASE WHEN p_sort_column = 'product' AND @order_dir_asc THEN p.name END ASC,
        CASE WHEN p_sort_column = 'product' AND NOT @order_dir_asc THEN p.name END DESC,
        CASE WHEN p_sort_column = 'cashier' AND @order_dir_asc THEN u.username END ASC,
        CASE WHEN p_sort_column = 'cashier' AND NOT @order_dir_asc THEN u.username END DESC,
        CASE WHEN p_sort_column = 'qty' AND @order_dir_asc THEN s.qty_sold END ASC,
        CASE WHEN p_sort_column = 'qty' AND NOT @order_dir_asc THEN s.qty_sold END DESC,
        CASE WHEN p_sort_column = 'subtotal' AND @order_dir_asc THEN (p.price * s.qty_sold) END ASC,
        CASE WHEN p_sort_column = 'subtotal' AND NOT @order_dir_asc THEN (p.price * s.qty_sold) END DESC,
        CASE WHEN p_sort_column = 'discount_amt' AND @order_dir_asc THEN ((p.price * s.qty_sold) - s.total_price) END ASC,
        CASE WHEN p_sort_column = 'discount_amt' AND NOT @order_dir_asc THEN ((p.price * s.qty_sold) - s.total_price) END DESC,
        CASE WHEN p_sort_column = 'price' AND @order_dir_asc THEN s.total_price END ASC, 
        CASE WHEN p_sort_column = 'price' AND NOT @order_dir_asc THEN s.total_price END DESC,
        CASE WHEN p_sort_column = 'discount' AND @order_dir_asc THEN s.discount_percent END ASC, 
        CASE WHEN p_sort_column = 'discount' AND NOT @order_dir_asc THEN s.discount_percent END DESC,
        CASE WHEN p_sort_column = 'date' AND @order_dir_asc THEN o.timestamp END ASC,
        CASE WHEN p_sort_column = 'date' AND NOT @order_dir_asc THEN o.timestamp END DESC,
        s.sale_id ASC;
END$$

CREATE PROCEDURE `ReportGetSalesSummaryByDate` (IN `p_date_start` DATE, IN `p_date_end` DATE)   BEGIN
    SELECT 
        p.name AS product_name,
        SUM(s.qty_sold) AS total_qty_sold,
        SUM(s.total_price) AS total_revenue
    FROM 
        sales s
    JOIN 
        orders o ON s.order_id = o.order_id -- Join Orders
    JOIN 
        products p ON s.product_id = p.product_id
    WHERE 
        DATE(o.timestamp) BETWEEN p_date_start AND p_date_end
    GROUP BY 
        p.product_id, p.name
    ORDER BY 
        total_qty_sold DESC;
END$$

CREATE PROCEDURE `ReportGetSalesSummaryToday` ()   BEGIN
    SELECT 
        COUNT(s.sale_id) AS totalSales,
        SUM(s.total_price) AS totalRevenue
    FROM 
        sales s
    JOIN 
        orders o ON s.order_id = o.order_id
    WHERE 
        DATE(o.timestamp) = CURDATE();
END$$

CREATE PROCEDURE `ReportGetStockAdjustmentHistory` ()   BEGIN
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

CREATE PROCEDURE `ReportGetStockAdjustmentHistoryByDate` (IN `p_date_start` DATE, IN `p_date_end` DATE)   BEGIN
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

CREATE PROCEDURE `ReportGetUnsoldProducts` (IN `p_date_start` DATE, IN `p_date_end` DATE)   BEGIN
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
        JOIN orders o ON s.order_id = o.order_id
        WHERE DATE(o.timestamp) BETWEEN p_date_start AND p_date_end
    ) AS sold_products ON p.product_id = sold_products.product_id
    WHERE
        p.status = 'available' 
        AND p.is_sellable = 1   
        AND sold_products.product_id IS NULL
    ORDER BY
        p.name;
END$$

CREATE PROCEDURE `SaleProcessReturn` (IN `p_sale_id` INT, IN `p_user_id` INT, IN `p_return_qty` INT, IN `p_reason` VARCHAR(255))   BEGIN
    DECLARE v_product_id INT;
    DECLARE v_original_qty INT;
    DECLARE v_already_returned INT;
    DECLARE v_max_returnable INT;
    DECLARE v_unit_price DECIMAL(10,2);
    DECLARE v_return_value DECIMAL(10,2);
    DECLARE v_order_id INT;
    -- NEW variable to hold the error message dynamically
    DECLARE v_error_message VARCHAR(255); 

    -- Get original sale details and lock the row
    SELECT product_id, qty_sold, (total_price / qty_sold), qty_returned, order_id
    INTO v_product_id, v_original_qty, v_unit_price, v_already_returned, v_order_id
    FROM sales
    WHERE sale_id = p_sale_id
    FOR UPDATE;

    -- Calculate max returnable
    SET v_max_returnable = v_original_qty - v_already_returned;

    -- Validation
    IF v_product_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Error: Original sale not found.';
    END IF;
    
    IF p_return_qty <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Error: Return quantity must be a positive number.';
    END IF;
    
    IF p_return_qty > v_max_returnable THEN
         -- FIX: Assign the concatenated message to a local variable
         SET v_error_message = CONCAT('Error: Cannot return more. Only ', v_max_returnable, ' items are available to be returned from this sale.');
         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_error_message;
    END IF;

    -- Calculate the value being "refunded"
    SET v_return_value = v_unit_price * p_return_qty;
    
    START TRANSACTION;

    -- 1. Add the stock back to the product
    UPDATE products
    SET stock_qty = stock_qty + p_return_qty
    WHERE product_id = v_product_id;

    -- 2. Log the return in the 'returns' table
    INSERT INTO `returns` (sale_id, product_id, user_id, qty_returned, return_value, reason, timestamp)
    VALUES (p_sale_id, v_product_id, p_user_id, p_return_qty, v_return_value, p_reason, NOW());
    
    -- 3. Update the 'sales' line item to reflect this return
    UPDATE sales
    SET qty_returned = qty_returned + p_return_qty
    WHERE sale_id = p_sale_id;
    
    -- 4. Update the main 'orders' total price
    UPDATE orders
    SET total_order_price = total_order_price - v_return_value
    WHERE order_id = v_order_id;

    COMMIT;
END$$

CREATE PROCEDURE `SaleRecordTransaction` (IN `user_id` INT, IN `product_id` INT, IN `qty_sold` INT, IN `p_discount_percent` DECIMAL(5,2), INOUT `p_order_id` INT, OUT `status` VARCHAR(100), OUT `sale_id` INT)   BEGIN
    DECLARE current_stock INT;
    DECLARE product_price DECIMAL(10,2);
    DECLARE product_status VARCHAR(20); 
    DECLARE total_price_line_item DECIMAL(10,2);

    SELECT products.stock_qty, products.price, products.status
    INTO current_stock, product_price, product_status
    FROM products
    WHERE products.product_id = product_id
    FOR UPDATE;

    IF product_status != 'available' THEN
        SET status = CONCAT('Error: Product is ', product_status, '.');
        SET sale_id = -1;
    ELSEIF current_stock < qty_sold THEN
        SET status = 'Error: Insufficient stock.';
        SET sale_id = -1;
    ELSEIF qty_sold <= 0 THEN
         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Error: Quantity sold must be a positive number.';
    ELSE
        SET total_price_line_item = (product_price * qty_sold) * (1 - (p_discount_percent / 100.0));

        START TRANSACTION;

        -- Create or Update Order Header (This holds the timestamp/user)
        IF p_order_id IS NULL OR p_order_id = 0 THEN
            INSERT INTO orders (user_id, total_order_price, timestamp)
            VALUES (user_id, total_price_line_item, NOW());
            SET p_order_id = LAST_INSERT_ID(); 
        ELSE
            UPDATE orders
            SET total_order_price = total_order_price + total_price_line_item
            WHERE order_id = p_order_id;
        END IF;

        UPDATE products
        SET stock_qty = stock_qty - qty_sold
        WHERE products.product_id = product_id;

        -- Record Sale Item (NO user_id or timestamp here anymore)
        INSERT INTO sales (order_id, product_id, qty_sold, total_price, discount_percent)
        VALUES (p_order_id, product_id, qty_sold, total_price_line_item, p_discount_percent);

        SET sale_id = LAST_INSERT_ID();
        SET status = 'Success: Sale recorded.';

        COMMIT;
    END IF;
END$$

CREATE PROCEDURE `UserCheckAvailability` (IN `p_username` VARCHAR(100), IN `p_email` VARCHAR(150), IN `p_phone` VARCHAR(11))   BEGIN
    SELECT user_id 
    FROM users 
    WHERE username = p_username 
       OR email = p_email 
       -- Only check phone if it's not empty/null to allow multiple users without phones
       OR (p_phone IS NOT NULL AND p_phone != '' AND phone_number = p_phone)
    LIMIT 1;
END$$

CREATE PROCEDURE `UserCheckAvailabilityForUpdate` (IN `p_user_id` INT, IN `p_username` VARCHAR(100) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci, IN `p_email` VARCHAR(150) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci, IN `p_phone` VARCHAR(11) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci)   BEGIN
    SELECT user_id 
    FROM users 
    WHERE (username = p_username OR email = p_email OR (p_phone IS NOT NULL AND p_phone != '' AND phone_number = p_phone))
      AND user_id != p_user_id
    LIMIT 1;
END$$

CREATE PROCEDURE `UserCreateAccount` (IN `p_username` VARCHAR(100), IN `p_hashed_password` VARCHAR(255), IN `p_role` ENUM('manager','cashier','assistant_manager'), IN `p_email` VARCHAR(150), IN `p_phone` VARCHAR(11))   BEGIN
    -- FIX: Changed p_phone_number to p_phone to match the parameter above
    INSERT INTO users (username, password, role, email, phone_number)
    VALUES (p_username, p_hashed_password, p_role, p_email, p_phone);
END$$

CREATE PROCEDURE `UserFindById` (IN `p_user_id` INT)   BEGIN
    SELECT user_id, username, role, email, phone_number FROM users WHERE user_id = p_user_id;
END$$

CREATE PROCEDURE `UserFindByPhone` (IN `p_phone_number` VARCHAR(11))   BEGIN
    SELECT * FROM users WHERE phone_number = p_phone_number;
END$$

CREATE PROCEDURE `UserLogin` (IN `p_username` VARCHAR(100) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci)   BEGIN
    SELECT * FROM users WHERE username = p_username;
END$$

CREATE PROCEDURE `UserRequestPasswordReset` (IN `email` VARCHAR(150), OUT `token` VARCHAR(255))   BEGIN
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

CREATE PROCEDURE `UserResetPassword` (IN `p_token_or_otp` VARCHAR(255), IN `p_new_hashed_password` VARCHAR(255))   BEGIN
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

CREATE PROCEDURE `UserStorePhoneOTP` (IN `p_user_id` INT, IN `p_otp_code` VARCHAR(10), IN `p_expiration_time` DATETIME)   BEGIN
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
(74, 3, 'gian123', 'success', 'Desktop', '2025-11-21 02:46:50'),
(75, 3, 'Gian123', 'success', 'Mobile', '2025-11-25 20:36:17');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `order_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'Cashier who processed the order (FK to users)',
  `total_order_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'The final total price of all items in the order',
  `timestamp` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Transaction date and time'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`order_id`, `user_id`, `total_order_price`, `timestamp`) VALUES
(1, 2, 20.00, '2025-10-26 06:05:00'),
(2, 2, 18.00, '2025-10-26 06:35:00'),
(3, 2, 50.00, '2025-10-26 07:05:00'),
(4, 2, 30.00, '2025-10-26 07:35:00'),
(5, 2, 40.00, '2025-10-26 08:05:00'),
(6, 2, 36.00, '2025-10-26 08:35:00'),
(7, 2, 60.00, '2025-10-26 09:05:00'),
(8, 2, 75.00, '2025-10-26 09:35:00'),
(9, 2, 75.00, '2025-10-26 10:05:00'),
(10, 2, 60.00, '2025-10-26 10:35:00'),
(11, 2, 60.00, '2025-10-26 11:05:00'),
(12, 2, 60.00, '2025-10-26 11:35:00'),
(13, 2, 90.00, '2025-10-26 12:05:00'),
(14, 2, 20.00, '2025-10-26 12:35:00'),
(15, 2, 54.00, '2025-10-27 06:25:00'),
(16, 2, 60.00, '2025-10-27 06:55:00'),
(17, 2, 75.00, '2025-10-27 07:25:00'),
(18, 2, 80.00, '2025-10-27 07:55:00'),
(19, 2, 60.00, '2025-10-27 08:25:00'),
(20, 2, 80.00, '2025-10-27 08:55:00'),
(21, 2, 60.00, '2025-10-27 09:25:00'),
(22, 2, 50.00, '2025-10-27 09:55:00'),
(23, 2, 75.00, '2025-10-27 10:25:00'),
(24, 2, 60.00, '2025-10-27 10:55:00'),
(25, 2, 48.00, '2025-10-27 11:25:00'),
(26, 2, 30.00, '2025-10-27 11:55:00'),
(27, 2, 30.00, '2025-10-27 12:25:00'),
(28, 2, 36.00, '2025-10-28 06:15:00'),
(29, 2, 100.00, '2025-10-28 06:45:00'),
(30, 2, 15.00, '2025-10-28 07:15:00'),
(31, 2, 40.00, '2025-10-28 07:45:00'),
(32, 2, 12.00, '2025-10-28 08:15:00'),
(33, 2, 40.00, '2025-10-28 08:45:00'),
(34, 2, 60.00, '2025-10-28 09:15:00'),
(35, 2, 50.00, '2025-10-28 09:45:00'),
(36, 2, 45.00, '2025-10-28 10:15:00'),
(37, 2, 90.00, '2025-10-28 10:45:00'),
(38, 2, 24.00, '2025-10-28 11:15:00'),
(39, 2, 60.00, '2025-10-28 11:45:00'),
(40, 2, 24.00, '2025-10-28 12:15:00'),
(41, 2, 72.00, '2025-10-29 06:25:00'),
(42, 2, 50.00, '2025-10-29 06:55:00'),
(43, 2, 15.00, '2025-10-29 07:25:00'),
(44, 2, 40.00, '2025-10-29 07:55:00'),
(45, 2, 60.00, '2025-10-29 08:25:00'),
(46, 2, 60.00, '2025-10-29 08:55:00'),
(47, 2, 75.00, '2025-10-29 09:25:00'),
(48, 2, 75.00, '2025-10-29 09:55:00'),
(49, 2, 30.00, '2025-10-29 10:25:00'),
(50, 2, 60.00, '2025-10-29 10:55:00'),
(51, 2, 60.00, '2025-10-29 11:25:00'),
(52, 2, 90.00, '2025-10-29 11:55:00'),
(53, 2, 20.00, '2025-10-29 12:35:00'),
(54, 2, 18.00, '2025-10-30 06:15:00'),
(55, 2, 80.00, '2025-10-30 06:45:00'),
(56, 2, 75.00, '2025-10-30 07:15:00'),
(57, 2, 80.00, '2025-10-30 07:45:00'),
(58, 2, 12.00, '2025-10-30 08:15:00'),
(59, 2, 100.00, '2025-10-30 08:45:00'),
(60, 2, 60.00, '2025-10-30 09:15:00'),
(61, 2, 25.00, '2025-10-30 09:45:00'),
(62, 2, 60.00, '2025-10-30 10:15:00'),
(63, 2, 45.00, '2025-10-30 10:45:00'),
(64, 2, 36.00, '2025-10-30 11:15:00'),
(65, 2, 90.00, '2025-10-30 11:45:00'),
(66, 2, 30.00, '2025-10-30 12:15:00'),
(67, 2, 72.00, '2025-10-31 06:25:00'),
(68, 2, 50.00, '2025-10-31 06:55:00'),
(69, 2, 60.00, '2025-10-31 07:25:00'),
(70, 2, 80.00, '2025-10-31 07:55:00'),
(71, 2, 60.00, '2025-10-31 08:25:00'),
(72, 2, 100.00, '2025-10-31 08:55:00'),
(73, 2, 75.00, '2025-10-31 09:25:00'),
(74, 2, 75.00, '2025-10-31 09:55:00'),
(75, 2, 30.00, '2025-10-31 10:25:00'),
(76, 2, 75.00, '2025-10-31 10:55:00'),
(77, 2, 36.00, '2025-10-31 11:25:00'),
(78, 2, 30.00, '2025-10-31 11:55:00'),
(79, 2, 20.00, '2025-11-01 06:05:00'),
(80, 2, 90.00, '2025-11-01 06:35:00'),
(81, 2, 100.00, '2025-11-01 07:05:00'),
(82, 2, 75.00, '2025-11-01 07:35:00'),
(83, 2, 40.00, '2025-11-01 08:05:00'),
(84, 2, 36.00, '2025-11-01 08:35:00'),
(85, 2, 80.00, '2025-11-01 09:05:00'),
(86, 2, 90.00, '2025-11-01 09:35:00'),
(87, 2, 75.00, '2025-11-01 10:05:00'),
(88, 2, 60.00, '2025-11-01 10:35:00'),
(89, 2, 60.00, '2025-11-01 11:05:00'),
(90, 2, 36.00, '2025-11-01 11:35:00'),
(91, 2, 30.00, '2025-11-01 12:05:00'),
(92, 2, 30.00, '2025-11-02 06:05:00'),
(93, 2, 54.00, '2025-11-02 06:35:00'),
(94, 2, 100.00, '2025-11-02 07:05:00'),
(95, 2, 45.00, '2025-11-02 07:35:00'),
(96, 2, 80.00, '2025-11-02 08:05:00'),
(97, 2, 48.00, '2025-11-02 08:35:00'),
(98, 2, 60.00, '2025-11-02 09:05:00'),
(99, 2, 90.00, '2025-11-02 09:35:00'),
(100, 2, 50.00, '2025-11-02 10:05:00'),
(101, 2, 45.00, '2025-11-02 10:35:00'),
(102, 2, 75.00, '2025-11-02 11:05:00'),
(103, 2, 48.00, '2025-11-02 11:35:00'),
(104, 2, 90.00, '2025-11-02 12:05:00'),
(105, 2, 20.00, '2025-11-02 12:35:00'),
(106, 2, 18.00, '2025-11-03 06:25:00'),
(107, 2, 100.00, '2025-11-03 06:55:00'),
(108, 2, 15.00, '2025-11-03 07:25:00'),
(109, 2, 40.00, '2025-11-03 07:55:00'),
(110, 2, 12.00, '2025-11-03 08:25:00'),
(111, 2, 40.00, '2025-11-03 08:55:00'),
(112, 2, 60.00, '2025-11-03 09:25:00'),
(113, 2, 50.00, '2025-11-03 09:55:00'),
(114, 2, 45.00, '2025-11-03 10:25:00'),
(115, 2, 90.00, '2025-11-03 10:55:00'),
(116, 2, 24.00, '2025-11-03 11:25:00'),
(117, 2, 60.00, '2025-11-03 11:55:00'),
(118, 2, 20.00, '2025-11-04 06:05:00'),
(119, 2, 72.00, '2025-11-04 06:35:00'),
(120, 2, 50.00, '2025-11-04 07:05:00'),
(121, 2, 60.00, '2025-11-04 07:35:00'),
(122, 2, 80.00, '2025-11-04 08:05:00'),
(123, 2, 60.00, '2025-11-04 08:35:00'),
(124, 2, 100.00, '2025-11-04 09:05:00'),
(125, 2, 75.00, '2025-11-04 09:35:00'),
(126, 2, 75.00, '2025-11-04 10:05:00'),
(127, 2, 30.00, '2025-11-04 10:35:00'),
(128, 2, 75.00, '2025-11-04 11:05:00'),
(129, 2, 36.00, '2025-11-04 11:35:00'),
(130, 2, 30.00, '2025-11-04 12:05:00'),
(131, 2, 20.00, '2025-11-04 12:35:00'),
(132, 2, 18.00, '2025-11-05 06:25:00'),
(133, 2, 80.00, '2025-11-05 06:55:00'),
(134, 2, 75.00, '2025-11-05 07:25:00'),
(135, 2, 80.00, '2025-11-05 07:55:00'),
(136, 2, 12.00, '2025-11-05 08:25:00'),
(137, 2, 100.00, '2025-11-05 08:55:00'),
(138, 2, 60.00, '2025-11-05 09:25:00'),
(139, 2, 25.00, '2025-11-05 09:55:00'),
(140, 2, 60.00, '2025-11-05 10:25:00'),
(141, 2, 45.00, '2025-11-05 10:55:00'),
(142, 2, 36.00, '2025-11-05 11:25:00'),
(143, 2, 90.00, '2025-11-05 11:55:00'),
(144, 2, 20.00, '2025-11-06 06:05:00'),
(145, 2, 72.00, '2025-11-06 06:35:00'),
(146, 2, 50.00, '2025-11-06 07:05:00'),
(147, 2, 15.00, '2025-11-06 07:35:00'),
(148, 2, 40.00, '2025-11-06 08:05:00'),
(149, 2, 60.00, '2025-11-06 08:35:00'),
(150, 2, 60.00, '2025-11-06 09:05:00'),
(151, 2, 75.00, '2025-11-06 09:35:00'),
(152, 2, 75.00, '2025-11-06 10:05:00'),
(153, 2, 30.00, '2025-11-06 10:35:00'),
(154, 2, 60.00, '2025-11-06 11:05:00'),
(155, 2, 60.00, '2025-11-06 11:35:00'),
(156, 2, 90.00, '2025-11-06 12:05:00'),
(157, 2, 20.00, '2025-11-06 12:35:00'),
(158, 2, 72.00, '2025-11-07 06:25:00'),
(159, 2, 50.00, '2025-11-07 06:55:00'),
(160, 2, 60.00, '2025-11-07 07:25:00'),
(161, 2, 80.00, '2025-11-07 07:55:00'),
(162, 2, 60.00, '2025-11-07 08:25:00'),
(163, 2, 100.00, '2025-11-07 08:55:00'),
(164, 2, 75.00, '2025-11-07 09:25:00'),
(165, 2, 75.00, '2025-11-07 09:55:00'),
(166, 2, 30.00, '2025-11-07 10:25:00'),
(167, 2, 75.00, '2025-11-07 10:55:00'),
(168, 2, 36.00, '2025-11-07 11:25:00'),
(169, 2, 30.00, '2025-11-07 11:55:00'),
(170, 2, 20.00, '2025-11-08 06:05:00'),
(171, 2, 90.00, '2025-11-08 06:35:00'),
(172, 2, 100.00, '2025-11-08 07:05:00'),
(173, 2, 75.00, '2025-11-08 07:35:00'),
(174, 2, 40.00, '2025-11-08 08:05:00'),
(175, 2, 36.00, '2025-11-08 08:35:00'),
(176, 2, 80.00, '2025-11-08 09:05:00'),
(177, 2, 90.00, '2025-11-08 09:35:00'),
(178, 2, 75.00, '2025-11-08 10:05:00'),
(179, 2, 60.00, '2025-11-08 10:35:00'),
(180, 2, 60.00, '2025-11-08 11:05:00'),
(181, 2, 36.00, '2025-11-08 11:35:00'),
(182, 2, 30.00, '2025-11-08 12:05:00'),
(183, 2, 30.00, '2025-11-09 06:05:00'),
(184, 2, 54.00, '2025-11-09 06:35:00'),
(185, 2, 100.00, '2025-11-09 07:05:00'),
(186, 2, 45.00, '2025-11-09 07:35:00'),
(187, 2, 80.00, '2025-11-09 08:05:00'),
(188, 2, 48.00, '2025-11-09 08:35:00'),
(189, 2, 60.00, '2025-11-09 09:05:00'),
(190, 2, 90.00, '2025-11-09 09:35:00'),
(191, 2, 50.00, '2025-11-09 10:05:00'),
(192, 2, 45.00, '2025-11-09 10:35:00'),
(193, 2, 75.00, '2025-11-09 11:05:00'),
(194, 2, 48.00, '2025-11-09 11:35:00'),
(195, 2, 90.00, '2025-11-09 12:05:00'),
(196, 2, 20.00, '2025-11-09 12:35:00'),
(197, 2, 18.00, '2025-11-10 06:25:00'),
(198, 2, 100.00, '2025-11-10 06:55:00'),
(199, 2, 15.00, '2025-11-10 07:25:00'),
(200, 2, 40.00, '2025-11-10 07:55:00'),
(201, 2, 12.00, '2025-11-10 08:25:00'),
(202, 2, 40.00, '2025-11-10 08:55:00'),
(203, 2, 60.00, '2025-11-10 09:25:00'),
(204, 2, 50.00, '2025-11-10 09:55:00'),
(205, 2, 45.00, '2025-11-10 10:25:00'),
(206, 2, 90.00, '2025-11-10 10:55:00'),
(207, 2, 24.00, '2025-11-10 11:25:00'),
(208, 2, 60.00, '2025-11-10 11:55:00'),
(209, 2, 20.00, '2025-11-11 06:05:00'),
(210, 2, 72.00, '2025-11-11 06:35:00'),
(211, 2, 50.00, '2025-11-11 07:05:00'),
(212, 2, 15.00, '2025-11-11 07:35:00'),
(213, 2, 40.00, '2025-11-11 08:05:00'),
(214, 2, 60.00, '2025-11-11 08:35:00'),
(215, 2, 60.00, '2025-11-11 09:05:00'),
(216, 2, 75.00, '2025-11-11 09:35:00'),
(217, 2, 75.00, '2025-11-11 10:05:00'),
(218, 2, 30.00, '2025-11-11 10:35:00'),
(219, 2, 60.00, '2025-11-11 11:05:00'),
(220, 2, 60.00, '2025-11-11 11:35:00'),
(221, 2, 90.00, '2025-11-11 12:05:00'),
(222, 2, 20.00, '2025-11-11 12:35:00'),
(223, 2, 18.00, '2025-11-12 06:25:00'),
(224, 2, 80.00, '2025-11-12 06:55:00'),
(225, 2, 75.00, '2025-11-12 07:25:00'),
(226, 2, 80.00, '2025-11-12 07:55:00'),
(227, 2, 12.00, '2025-11-12 08:25:00'),
(228, 2, 100.00, '2025-11-12 08:55:00'),
(229, 2, 60.00, '2025-11-12 09:25:00'),
(230, 2, 25.00, '2025-11-12 09:55:00'),
(231, 2, 60.00, '2025-11-12 10:25:00'),
(232, 2, 45.00, '2025-11-12 10:55:00'),
(233, 2, 36.00, '2025-11-12 11:25:00'),
(234, 2, 90.00, '2025-11-12 11:55:00'),
(235, 2, 20.00, '2025-11-13 06:05:00'),
(236, 2, 72.00, '2025-11-13 06:35:00'),
(237, 2, 50.00, '2025-11-13 07:05:00'),
(238, 2, 15.00, '2025-11-13 07:35:00'),
(239, 2, 40.00, '2025-11-13 08:05:00'),
(240, 2, 60.00, '2025-11-13 08:35:00'),
(241, 2, 60.00, '2025-11-13 09:05:00'),
(242, 2, 75.00, '2025-11-13 09:35:00'),
(243, 2, 75.00, '2025-11-13 10:05:00'),
(244, 2, 30.00, '2025-11-13 10:35:00'),
(245, 2, 60.00, '2025-11-13 11:05:00'),
(246, 2, 60.00, '2025-11-13 11:35:00'),
(247, 2, 90.00, '2025-11-13 12:05:00'),
(248, 2, 20.00, '2025-11-13 12:35:00'),
(249, 2, 72.00, '2025-11-14 06:25:00'),
(250, 2, 50.00, '2025-11-14 06:55:00'),
(251, 2, 60.00, '2025-11-14 07:25:00'),
(252, 2, 80.00, '2025-11-14 07:55:00'),
(253, 2, 60.00, '2025-11-14 08:25:00'),
(254, 2, 100.00, '2025-11-14 08:55:00'),
(255, 2, 75.00, '2025-11-14 09:25:00'),
(256, 2, 75.00, '2025-11-14 09:55:00'),
(257, 2, 30.00, '2025-11-14 10:25:00'),
(258, 2, 75.00, '2025-11-14 10:55:00'),
(259, 2, 36.00, '2025-11-14 11:25:00'),
(260, 2, 30.00, '2025-11-14 11:55:00'),
(261, 2, 20.00, '2025-11-15 06:05:00'),
(262, 2, 90.00, '2025-11-15 06:35:00'),
(263, 2, 100.00, '2025-11-15 07:05:00'),
(264, 2, 75.00, '2025-11-15 07:35:00'),
(265, 2, 40.00, '2025-11-15 08:05:00'),
(266, 2, 36.00, '2025-11-15 08:35:00'),
(267, 2, 80.00, '2025-11-15 09:05:00'),
(268, 2, 90.00, '2025-11-15 09:35:00'),
(269, 2, 75.00, '2025-11-15 10:05:00'),
(270, 2, 60.00, '2025-11-15 10:35:00'),
(271, 2, 60.00, '2025-11-15 11:05:00'),
(272, 2, 36.00, '2025-11-15 11:35:00'),
(273, 2, 30.00, '2025-11-15 12:05:00'),
(274, 2, 30.00, '2025-11-16 06:05:00'),
(275, 2, 54.00, '2025-11-16 06:35:00'),
(276, 2, 100.00, '2025-11-16 07:05:00'),
(277, 2, 45.00, '2025-11-16 07:35:00'),
(278, 2, 80.00, '2025-11-16 08:05:00'),
(279, 2, 48.00, '2025-11-16 08:35:00'),
(280, 2, 60.00, '2025-11-16 09:05:00'),
(281, 2, 90.00, '2025-11-16 09:35:00'),
(282, 2, 50.00, '2025-11-16 10:05:00'),
(283, 2, 45.00, '2025-11-16 10:35:00'),
(284, 2, 75.00, '2025-11-16 11:05:00'),
(285, 2, 48.00, '2025-11-16 11:35:00'),
(286, 2, 90.00, '2025-11-16 12:05:00'),
(287, 2, 20.00, '2025-11-16 12:35:00'),
(288, 2, 18.00, '2025-11-17 06:25:00'),
(289, 2, 100.00, '2025-11-17 06:55:00'),
(290, 2, 15.00, '2025-11-17 07:25:00'),
(291, 2, 40.00, '2025-11-17 07:55:00'),
(292, 2, 12.00, '2025-11-17 08:25:00'),
(293, 2, 40.00, '2025-11-17 08:55:00'),
(294, 2, 60.00, '2025-11-17 09:25:00'),
(295, 2, 50.00, '2025-11-17 09:55:00'),
(296, 2, 45.00, '2025-11-17 10:25:00'),
(297, 2, 90.00, '2025-11-17 10:55:00'),
(298, 2, 24.00, '2025-11-17 11:25:00'),
(299, 2, 60.00, '2025-11-17 11:55:00'),
(300, 2, 20.00, '2025-11-18 06:05:00'),
(301, 2, 72.00, '2025-11-18 06:35:00'),
(302, 2, 50.00, '2025-11-18 07:05:00'),
(303, 2, 15.00, '2025-11-18 07:35:00'),
(304, 2, 40.00, '2025-11-18 08:05:00'),
(305, 2, 60.00, '2025-11-18 08:35:00'),
(306, 2, 60.00, '2025-11-18 09:05:00'),
(307, 2, 75.00, '2025-11-18 09:35:00'),
(308, 2, 75.00, '2025-11-18 10:05:00'),
(309, 2, 30.00, '2025-11-18 10:35:00'),
(310, 2, 60.00, '2025-11-18 11:05:00'),
(311, 2, 60.00, '2025-11-18 11:35:00'),
(312, 2, 90.00, '2025-11-18 12:05:00'),
(313, 2, 20.00, '2025-11-18 12:35:00'),
(314, 2, 18.00, '2025-11-19 06:25:00'),
(315, 2, 80.00, '2025-11-19 06:55:00'),
(316, 2, 75.00, '2025-11-19 07:25:00'),
(317, 2, 80.00, '2025-11-19 07:55:00'),
(318, 2, 12.00, '2025-11-19 08:25:00'),
(319, 2, 100.00, '2025-11-19 08:55:00'),
(320, 2, 60.00, '2025-11-19 09:25:00'),
(321, 2, 25.00, '2025-11-19 09:55:00'),
(322, 2, 60.00, '2025-11-19 10:25:00'),
(323, 2, 45.00, '2025-11-19 10:55:00'),
(324, 2, 36.00, '2025-11-19 11:25:00'),
(325, 2, 90.00, '2025-11-19 11:55:00'),
(326, 2, 20.00, '2025-11-20 06:05:00'),
(327, 2, 72.00, '2025-11-20 06:35:00'),
(328, 2, 50.00, '2025-11-20 07:05:00'),
(329, 2, 15.00, '2025-11-20 07:35:00'),
(330, 2, 40.00, '2025-11-20 08:05:00'),
(331, 2, 60.00, '2025-11-20 08:35:00'),
(332, 2, 60.00, '2025-11-20 09:05:00'),
(333, 2, 75.00, '2025-11-20 09:35:00'),
(334, 2, 75.00, '2025-11-20 10:05:00'),
(335, 2, 30.00, '2025-11-20 10:35:00'),
(336, 2, 60.00, '2025-11-20 11:05:00'),
(337, 2, 60.00, '2025-11-20 11:35:00'),
(338, 2, 90.00, '2025-11-20 12:05:00'),
(339, 2, 20.00, '2025-11-20 12:35:00'),
(340, 2, 72.00, '2025-11-21 06:25:00'),
(341, 2, 50.00, '2025-11-21 06:55:00'),
(342, 2, 60.00, '2025-11-21 07:25:00'),
(343, 2, 80.00, '2025-11-21 07:55:00'),
(344, 2, 60.00, '2025-11-21 08:25:00'),
(345, 2, 100.00, '2025-11-21 08:55:00'),
(346, 2, 75.00, '2025-11-21 09:25:00'),
(347, 2, 75.00, '2025-11-21 09:55:00'),
(348, 2, 30.00, '2025-11-21 10:25:00'),
(349, 2, 75.00, '2025-11-21 10:55:00'),
(350, 2, 36.00, '2025-11-21 11:25:00'),
(351, 2, 30.00, '2025-11-21 11:55:00'),
(352, 2, 20.00, '2025-11-22 06:05:00'),
(353, 2, 90.00, '2025-11-22 06:35:00'),
(354, 2, 100.00, '2025-11-22 07:05:00'),
(355, 2, 75.00, '2025-11-22 07:35:00'),
(356, 2, 40.00, '2025-11-22 08:05:00'),
(357, 2, 36.00, '2025-11-22 08:35:00'),
(358, 2, 80.00, '2025-11-22 09:05:00'),
(359, 2, 90.00, '2025-11-22 09:35:00'),
(360, 2, 75.00, '2025-11-22 10:05:00'),
(361, 2, 60.00, '2025-11-22 10:35:00'),
(362, 2, 60.00, '2025-11-22 11:05:00'),
(363, 2, 36.00, '2025-11-22 11:35:00'),
(364, 2, 30.00, '2025-11-22 12:05:00'),
(365, 2, 20.00, '2025-11-22 12:35:00'),
(366, 2, 54.00, '2025-11-23 06:25:00'),
(367, 2, 100.00, '2025-11-23 06:55:00'),
(368, 2, 45.00, '2025-11-23 07:25:00'),
(369, 2, 80.00, '2025-11-23 07:55:00'),
(370, 2, 48.00, '2025-11-23 08:35:00'),
(371, 2, 60.00, '2025-11-23 09:05:00'),
(372, 2, 90.00, '2025-11-23 09:35:00'),
(373, 2, 50.00, '2025-11-23 10:05:00'),
(374, 2, 45.00, '2025-11-23 10:35:00'),
(375, 2, 75.00, '2025-11-23 11:05:00'),
(376, 2, 48.00, '2025-11-23 11:35:00'),
(377, 2, 90.00, '2025-11-23 12:05:00'),
(378, 2, 20.00, '2025-11-23 12:35:00'),
(379, 2, 18.00, '2025-11-24 06:25:00'),
(380, 2, 100.00, '2025-11-24 06:55:00'),
(381, 2, 15.00, '2025-11-24 07:25:00'),
(382, 2, 40.00, '2025-11-24 07:55:00'),
(383, 2, 12.00, '2025-11-24 08:25:00'),
(384, 2, 40.00, '2025-11-24 08:55:00'),
(385, 2, 60.00, '2025-11-24 09:25:00'),
(386, 2, 50.00, '2025-11-24 09:55:00'),
(387, 2, 45.00, '2025-11-24 10:25:00'),
(388, 2, 90.00, '2025-11-24 10:55:00'),
(389, 2, 24.00, '2025-11-24 11:25:00'),
(390, 2, 60.00, '2025-11-24 11:55:00'),
(391, 2, 20.00, '2025-11-25 06:05:00'),
(392, 2, 72.00, '2025-11-25 06:35:00'),
(393, 2, 50.00, '2025-11-25 07:05:00'),
(394, 2, 15.00, '2025-11-25 07:35:00'),
(395, 2, 40.00, '2025-11-25 08:05:00'),
(396, 2, 60.00, '2025-11-25 08:35:00'),
(397, 2, 60.00, '2025-11-25 09:05:00'),
(398, 2, 75.00, '2025-11-25 09:35:00'),
(399, 2, 75.00, '2025-11-25 10:05:00'),
(400, 2, 30.00, '2025-11-25 10:35:00'),
(401, 2, 60.00, '2025-11-25 11:05:00'),
(402, 2, 60.00, '2025-11-25 11:35:00'),
(403, 2, 90.00, '2025-11-25 12:05:00'),
(404, 2, 20.00, '2025-11-25 12:35:00'),
(405, 5, 45.00, '2025-10-26 06:15:00'),
(406, 5, 60.00, '2025-10-26 06:45:00'),
(407, 5, 36.00, '2025-10-26 07:15:00'),
(408, 5, 30.00, '2025-10-26 07:45:00'),
(409, 5, 16.00, '2025-10-26 08:15:00'),
(410, 5, 36.00, '2025-10-26 08:45:00'),
(411, 5, 100.00, '2025-10-26 09:15:00'),
(412, 5, 15.00, '2025-10-26 09:45:00'),
(413, 5, 80.00, '2025-10-26 10:15:00'),
(414, 5, 60.00, '2025-10-26 10:45:00'),
(415, 5, 100.00, '2025-10-26 11:15:00'),
(416, 5, 45.00, '2025-10-26 11:45:00'),
(417, 5, 25.00, '2025-10-26 12:15:00'),
(418, 5, 30.00, '2025-10-27 06:05:00'),
(419, 5, 75.00, '2025-10-27 06:35:00'),
(420, 5, 24.00, '2025-10-27 07:05:00'),
(421, 5, 60.00, '2025-10-27 07:35:00'),
(422, 5, 24.00, '2025-10-27 08:05:00'),
(423, 5, 18.00, '2025-10-27 08:35:00'),
(424, 5, 50.00, '2025-10-27 09:05:00'),
(425, 5, 15.00, '2025-10-27 09:35:00'),
(426, 5, 40.00, '2025-10-27 10:05:00'),
(427, 5, 12.00, '2025-10-27 10:35:00'),
(428, 5, 40.00, '2025-10-27 11:05:00'),
(429, 5, 75.00, '2025-10-27 11:35:00'),
(430, 5, 75.00, '2025-10-27 12:05:00'),
(431, 5, 15.00, '2025-10-27 12:35:00'),
(432, 5, 60.00, '2025-10-28 06:25:00'),
(433, 5, 48.00, '2025-10-28 06:55:00'),
(434, 5, 90.00, '2025-10-28 07:25:00'),
(435, 5, 20.00, '2025-10-28 07:55:00'),
(436, 5, 72.00, '2025-10-28 08:25:00'),
(437, 5, 50.00, '2025-10-28 08:55:00'),
(438, 5, 75.00, '2025-10-28 09:25:00'),
(439, 5, 40.00, '2025-10-28 09:55:00'),
(440, 5, 48.00, '2025-10-28 10:25:00'),
(441, 5, 60.00, '2025-10-28 10:55:00'),
(442, 5, 45.00, '2025-10-28 11:25:00'),
(443, 5, 75.00, '2025-10-28 11:55:00'),
(444, 5, 75.00, '2025-10-29 06:05:00'),
(445, 5, 45.00, '2025-10-29 06:35:00'),
(446, 5, 36.00, '2025-10-29 07:05:00'),
(447, 5, 60.00, '2025-10-29 07:35:00'),
(448, 5, 20.00, '2025-10-29 08:05:00'),
(449, 5, 18.00, '2025-10-29 08:35:00'),
(450, 5, 100.00, '2025-10-29 09:05:00'),
(451, 5, 30.00, '2025-10-29 09:35:00'),
(452, 5, 80.00, '2025-10-29 10:05:00'),
(453, 5, 12.00, '2025-10-29 10:35:00'),
(454, 5, 100.00, '2025-10-29 11:05:00'),
(455, 5, 45.00, '2025-10-29 11:35:00'),
(456, 5, 25.00, '2025-10-29 12:05:00'),
(457, 5, 45.00, '2025-10-29 12:45:00'),
(458, 5, 75.00, '2025-10-30 06:25:00'),
(459, 5, 48.00, '2025-10-30 06:55:00'),
(460, 5, 30.00, '2025-10-30 07:25:00'),
(461, 5, 20.00, '2025-10-30 07:55:00'),
(462, 5, 54.00, '2025-10-30 08:25:00'),
(463, 5, 50.00, '2025-10-30 08:55:00'),
(464, 5, 15.00, '2025-10-30 09:25:00'),
(465, 5, 40.00, '2025-10-30 09:55:00'),
(466, 5, 60.00, '2025-10-30 10:25:00'),
(467, 5, 80.00, '2025-10-30 10:55:00'),
(468, 5, 75.00, '2025-10-30 11:25:00'),
(469, 5, 25.00, '2025-10-30 11:55:00'),
(470, 5, 75.00, '2025-10-31 06:05:00'),
(471, 5, 90.00, '2025-10-31 06:35:00'),
(472, 5, 24.00, '2025-10-31 07:05:00'),
(473, 5, 60.00, '2025-10-31 07:35:00'),
(474, 5, 20.00, '2025-10-31 08:05:00'),
(475, 5, 54.00, '2025-10-31 08:35:00'),
(476, 5, 80.00, '2025-10-31 09:05:00'),
(477, 5, 15.00, '2025-10-31 09:35:00'),
(478, 5, 40.00, '2025-10-31 10:05:00'),
(479, 5, 12.00, '2025-10-31 10:35:00'),
(480, 5, 60.00, '2025-10-31 11:05:00'),
(481, 5, 60.00, '2025-10-31 11:35:00'),
(482, 5, 50.00, '2025-10-31 12:05:00'),
(483, 5, 45.00, '2025-11-01 06:15:00'),
(484, 5, 75.00, '2025-11-01 06:45:00'),
(485, 5, 60.00, '2025-11-01 07:15:00'),
(486, 5, 90.00, '2025-11-01 07:45:00'),
(487, 5, 24.00, '2025-11-01 08:15:00'),
(488, 5, 72.00, '2025-11-01 08:45:00'),
(489, 5, 120.00, '2025-11-01 09:15:00'),
(490, 5, 45.00, '2025-11-01 09:45:00'),
(491, 5, 80.00, '2025-11-01 10:15:00'),
(492, 5, 24.00, '2025-11-01 10:45:00'),
(493, 5, 100.00, '2025-11-01 11:15:00'),
(494, 5, 60.00, '2025-11-01 11:45:00'),
(495, 5, 25.00, '2025-11-01 12:15:00'),
(496, 5, 60.00, '2025-11-02 06:15:00'),
(497, 5, 90.00, '2025-11-02 06:45:00'),
(498, 5, 48.00, '2025-11-02 07:15:00'),
(499, 5, 60.00, '2025-11-02 07:45:00'),
(500, 5, 40.00, '2025-11-02 08:15:00'),
(501, 5, 36.00, '2025-11-02 08:45:00'),
(502, 5, 150.00, '2025-11-02 09:15:00'),
(503, 5, 60.00, '2025-11-02 09:45:00'),
(504, 5, 120.00, '2025-11-02 10:15:00'),
(505, 5, 12.00, '2025-11-02 10:45:00'),
(506, 5, 40.00, '2025-11-02 11:15:00'),
(507, 5, 75.00, '2025-11-02 11:45:00'),
(508, 5, 25.00, '2025-11-02 12:15:00'),
(509, 5, 30.00, '2025-11-03 06:05:00'),
(510, 5, 75.00, '2025-11-03 06:35:00'),
(511, 5, 48.00, '2025-11-03 07:05:00'),
(512, 5, 90.00, '2025-11-03 07:35:00'),
(513, 5, 20.00, '2025-11-03 08:05:00'),
(514, 5, 72.00, '2025-11-03 08:35:00'),
(515, 5, 50.00, '2025-11-03 09:05:00'),
(516, 5, 75.00, '2025-11-03 09:35:00'),
(517, 5, 40.00, '2025-11-03 10:05:00'),
(518, 5, 48.00, '2025-11-03 10:35:00'),
(519, 5, 60.00, '2025-11-03 11:05:00'),
(520, 5, 45.00, '2025-11-03 11:35:00'),
(521, 5, 75.00, '2025-11-03 12:05:00'),
(522, 5, 75.00, '2025-11-04 06:15:00'),
(523, 5, 90.00, '2025-11-04 06:45:00'),
(524, 5, 24.00, '2025-11-04 07:15:00'),
(525, 5, 60.00, '2025-11-04 07:45:00'),
(526, 5, 20.00, '2025-11-04 08:15:00'),
(527, 5, 54.00, '2025-11-04 08:45:00'),
(528, 5, 80.00, '2025-11-04 09:15:00'),
(529, 5, 15.00, '2025-11-04 09:45:00'),
(530, 5, 40.00, '2025-11-04 10:15:00'),
(531, 5, 12.00, '2025-11-04 10:45:00'),
(532, 5, 60.00, '2025-11-04 11:15:00'),
(533, 5, 60.00, '2025-11-04 11:45:00'),
(534, 5, 50.00, '2025-11-04 12:15:00'),
(535, 5, 45.00, '2025-11-05 06:05:00'),
(536, 5, 75.00, '2025-11-05 06:35:00'),
(537, 5, 48.00, '2025-11-05 07:05:00'),
(538, 5, 30.00, '2025-11-05 07:35:00'),
(539, 5, 20.00, '2025-11-05 08:05:00'),
(540, 5, 54.00, '2025-11-05 08:35:00'),
(541, 5, 50.00, '2025-11-05 09:05:00'),
(542, 5, 15.00, '2025-11-05 09:35:00'),
(543, 5, 40.00, '2025-11-05 10:05:00'),
(544, 5, 60.00, '2025-11-05 10:35:00'),
(545, 5, 80.00, '2025-11-05 11:05:00'),
(546, 5, 75.00, '2025-11-05 11:35:00'),
(547, 5, 25.00, '2025-11-05 12:05:00'),
(548, 5, 75.00, '2025-11-06 06:15:00'),
(549, 5, 45.00, '2025-11-06 06:45:00'),
(550, 5, 36.00, '2025-11-06 07:15:00'),
(551, 5, 60.00, '2025-11-06 07:45:00'),
(552, 5, 20.00, '2025-11-06 08:15:00'),
(553, 5, 18.00, '2025-11-06 08:45:00'),
(554, 5, 100.00, '2025-11-06 09:15:00'),
(555, 5, 30.00, '2025-11-06 09:45:00'),
(556, 5, 80.00, '2025-11-06 10:15:00'),
(557, 5, 12.00, '2025-11-06 10:45:00'),
(558, 5, 100.00, '2025-11-06 11:15:00'),
(559, 5, 45.00, '2025-11-06 11:45:00'),
(560, 5, 25.00, '2025-11-06 12:15:00'),
(561, 5, 75.00, '2025-11-07 06:05:00'),
(562, 5, 90.00, '2025-11-07 06:35:00'),
(563, 5, 24.00, '2025-11-07 07:05:00'),
(564, 5, 60.00, '2025-11-07 07:35:00'),
(565, 5, 20.00, '2025-11-07 08:05:00'),
(566, 5, 54.00, '2025-11-07 08:35:00'),
(567, 5, 80.00, '2025-11-07 09:05:00'),
(568, 5, 15.00, '2025-11-07 09:35:00'),
(569, 5, 40.00, '2025-11-07 10:05:00'),
(570, 5, 12.00, '2025-11-07 10:35:00'),
(571, 5, 60.00, '2025-11-07 11:05:00'),
(572, 5, 60.00, '2025-11-07 11:35:00'),
(573, 5, 50.00, '2025-11-07 12:05:00'),
(574, 5, 45.00, '2025-11-08 06:15:00'),
(575, 5, 75.00, '2025-11-08 06:45:00'),
(576, 5, 60.00, '2025-11-08 07:15:00'),
(577, 5, 90.00, '2025-11-08 07:45:00'),
(578, 5, 24.00, '2025-11-08 08:15:00'),
(579, 5, 72.00, '2025-11-08 08:45:00'),
(580, 5, 120.00, '2025-11-08 09:15:00'),
(581, 5, 45.00, '2025-11-08 09:45:00'),
(582, 5, 80.00, '2025-11-08 10:15:00'),
(583, 5, 24.00, '2025-11-08 10:45:00'),
(584, 5, 100.00, '2025-11-08 11:15:00'),
(585, 5, 60.00, '2025-11-08 11:45:00'),
(586, 5, 25.00, '2025-11-08 12:15:00'),
(587, 5, 60.00, '2025-11-09 06:15:00'),
(588, 5, 90.00, '2025-11-09 06:45:00'),
(589, 5, 48.00, '2025-11-09 07:15:00'),
(590, 5, 60.00, '2025-11-09 07:45:00'),
(591, 5, 40.00, '2025-11-09 08:15:00'),
(592, 5, 36.00, '2025-11-09 08:45:00'),
(593, 5, 150.00, '2025-11-09 09:15:00'),
(594, 5, 60.00, '2025-11-09 09:45:00'),
(595, 5, 120.00, '2025-11-09 10:15:00'),
(596, 5, 12.00, '2025-11-09 10:45:00'),
(597, 5, 40.00, '2025-11-09 11:15:00'),
(598, 5, 75.00, '2025-11-09 11:45:00'),
(599, 5, 25.00, '2025-11-09 12:15:00'),
(600, 5, 30.00, '2025-11-10 06:05:00'),
(601, 5, 75.00, '2025-11-10 06:35:00'),
(602, 5, 48.00, '2025-11-10 07:05:00'),
(603, 5, 90.00, '2025-11-10 07:35:00'),
(604, 5, 20.00, '2025-11-10 08:05:00'),
(605, 5, 72.00, '2025-11-10 08:35:00'),
(606, 5, 50.00, '2025-11-10 09:05:00'),
(607, 5, 75.00, '2025-11-10 09:35:00'),
(608, 5, 40.00, '2025-11-10 10:05:00'),
(609, 5, 48.00, '2025-11-10 10:35:00'),
(610, 5, 60.00, '2025-11-10 11:05:00'),
(611, 5, 45.00, '2025-11-10 11:35:00'),
(612, 5, 75.00, '2025-11-10 12:05:00'),
(613, 5, 75.00, '2025-11-11 06:15:00'),
(614, 5, 45.00, '2025-11-11 06:45:00'),
(615, 5, 36.00, '2025-11-11 07:15:00'),
(616, 5, 60.00, '2025-11-11 07:45:00'),
(617, 5, 20.00, '2025-11-11 08:15:00'),
(618, 5, 18.00, '2025-11-11 08:45:00'),
(619, 5, 100.00, '2025-11-11 09:15:00'),
(620, 5, 30.00, '2025-11-11 09:45:00'),
(621, 5, 80.00, '2025-11-11 10:15:00'),
(622, 5, 12.00, '2025-11-11 10:45:00'),
(623, 5, 100.00, '2025-11-11 11:15:00'),
(624, 5, 45.00, '2025-11-11 11:45:00'),
(625, 5, 25.00, '2025-11-11 12:15:00'),
(626, 5, 45.00, '2025-11-12 06:05:00'),
(627, 5, 75.00, '2025-11-12 06:35:00'),
(628, 5, 48.00, '2025-11-12 07:05:00'),
(629, 5, 30.00, '2025-11-12 07:35:00'),
(630, 5, 20.00, '2025-11-12 08:05:00'),
(631, 5, 54.00, '2025-11-12 08:35:00'),
(632, 5, 50.00, '2025-11-12 09:05:00'),
(633, 5, 15.00, '2025-11-12 09:35:00'),
(634, 5, 40.00, '2025-11-12 10:05:00'),
(635, 5, 60.00, '2025-11-12 10:35:00'),
(636, 5, 80.00, '2025-11-12 11:05:00'),
(637, 5, 75.00, '2025-11-12 11:35:00'),
(638, 5, 25.00, '2025-11-12 12:05:00'),
(639, 5, 75.00, '2025-11-13 06:15:00'),
(640, 5, 45.00, '2025-11-13 06:45:00'),
(641, 5, 36.00, '2025-11-13 07:15:00'),
(642, 5, 60.00, '2025-11-13 07:45:00'),
(643, 5, 20.00, '2025-11-13 08:15:00'),
(644, 5, 18.00, '2025-11-13 08:45:00'),
(645, 5, 100.00, '2025-11-13 09:15:00'),
(646, 5, 30.00, '2025-11-13 09:45:00'),
(647, 5, 80.00, '2025-11-13 10:15:00'),
(648, 5, 12.00, '2025-11-13 10:45:00'),
(649, 5, 100.00, '2025-11-13 11:15:00'),
(650, 5, 45.00, '2025-11-13 11:45:00'),
(651, 5, 25.00, '2025-11-13 12:15:00'),
(652, 5, 75.00, '2025-11-14 06:05:00'),
(653, 5, 90.00, '2025-11-14 06:35:00'),
(654, 5, 24.00, '2025-11-14 07:05:00'),
(655, 5, 60.00, '2025-11-14 07:35:00'),
(656, 5, 20.00, '2025-11-14 08:05:00'),
(657, 5, 54.00, '2025-11-14 08:35:00'),
(658, 5, 80.00, '2025-11-14 09:05:00'),
(659, 5, 15.00, '2025-11-14 09:35:00'),
(660, 5, 40.00, '2025-11-14 10:05:00'),
(661, 5, 12.00, '2025-11-14 10:35:00'),
(662, 5, 60.00, '2025-11-14 11:05:00'),
(663, 5, 60.00, '2025-11-14 11:35:00'),
(664, 5, 50.00, '2025-11-14 12:05:00'),
(665, 5, 45.00, '2025-11-15 06:15:00'),
(666, 5, 75.00, '2025-11-15 06:45:00'),
(667, 5, 60.00, '2025-11-15 07:15:00'),
(668, 5, 90.00, '2025-11-15 07:45:00'),
(669, 5, 24.00, '2025-11-15 08:15:00'),
(670, 5, 72.00, '2025-11-15 08:45:00'),
(671, 5, 120.00, '2025-11-15 09:15:00'),
(672, 5, 45.00, '2025-11-15 09:45:00'),
(673, 5, 80.00, '2025-11-15 10:15:00'),
(674, 5, 24.00, '2025-11-15 10:45:00'),
(675, 5, 100.00, '2025-11-15 11:15:00'),
(676, 5, 60.00, '2025-11-15 11:45:00'),
(677, 5, 25.00, '2025-11-15 12:15:00'),
(678, 5, 60.00, '2025-11-16 06:15:00'),
(679, 5, 90.00, '2025-11-16 06:45:00'),
(680, 5, 48.00, '2025-11-16 07:15:00'),
(681, 5, 60.00, '2025-11-16 07:45:00'),
(682, 5, 40.00, '2025-11-16 08:15:00'),
(683, 5, 36.00, '2025-11-16 08:45:00'),
(684, 5, 150.00, '2025-11-16 09:15:00'),
(685, 5, 60.00, '2025-11-16 09:45:00'),
(686, 5, 120.00, '2025-11-16 10:15:00'),
(687, 5, 12.00, '2025-11-16 10:45:00'),
(688, 5, 40.00, '2025-11-16 11:15:00'),
(689, 5, 75.00, '2025-11-16 11:45:00'),
(690, 5, 25.00, '2025-11-16 12:15:00'),
(691, 5, 30.00, '2025-11-17 06:05:00'),
(692, 5, 75.00, '2025-11-17 06:35:00'),
(693, 5, 48.00, '2025-11-17 07:05:00'),
(694, 5, 90.00, '2025-11-17 07:35:00'),
(695, 5, 20.00, '2025-11-17 08:05:00'),
(696, 5, 72.00, '2025-11-17 08:35:00'),
(697, 5, 50.00, '2025-11-17 09:05:00'),
(698, 5, 75.00, '2025-11-17 09:35:00'),
(699, 5, 40.00, '2025-11-17 10:05:00'),
(700, 5, 48.00, '2025-11-17 10:35:00'),
(701, 5, 60.00, '2025-11-17 11:05:00'),
(702, 5, 45.00, '2025-11-17 11:35:00'),
(703, 5, 75.00, '2025-11-17 12:05:00'),
(704, 5, 75.00, '2025-11-18 06:15:00'),
(705, 5, 45.00, '2025-11-18 06:45:00'),
(706, 5, 36.00, '2025-11-18 07:15:00'),
(707, 5, 60.00, '2025-11-18 07:45:00'),
(708, 5, 20.00, '2025-11-18 08:15:00'),
(709, 5, 18.00, '2025-11-18 08:45:00'),
(710, 5, 100.00, '2025-11-18 09:15:00'),
(711, 5, 30.00, '2025-11-18 09:45:00'),
(712, 5, 80.00, '2025-11-18 10:15:00'),
(713, 5, 12.00, '2025-11-18 10:45:00'),
(714, 5, 100.00, '2025-11-18 11:15:00'),
(715, 5, 45.00, '2025-11-18 11:45:00'),
(716, 5, 25.00, '2025-11-18 12:15:00'),
(717, 5, 45.00, '2025-11-19 06:05:00'),
(718, 5, 75.00, '2025-11-19 06:35:00'),
(719, 5, 48.00, '2025-11-19 07:05:00'),
(720, 5, 30.00, '2025-11-19 07:35:00'),
(721, 5, 20.00, '2025-11-19 08:05:00'),
(722, 5, 54.00, '2025-11-19 08:35:00'),
(723, 5, 50.00, '2025-11-19 09:05:00'),
(724, 5, 15.00, '2025-11-19 09:35:00'),
(725, 5, 40.00, '2025-11-19 10:05:00'),
(726, 5, 60.00, '2025-11-19 10:35:00'),
(727, 5, 80.00, '2025-11-19 11:05:00'),
(728, 5, 75.00, '2025-11-19 11:35:00'),
(729, 5, 25.00, '2025-11-19 12:05:00'),
(730, 5, 75.00, '2025-11-20 06:15:00'),
(731, 5, 45.00, '2025-11-20 06:45:00'),
(732, 5, 36.00, '2025-11-20 07:15:00'),
(733, 5, 60.00, '2025-11-20 07:45:00'),
(734, 5, 20.00, '2025-11-20 08:15:00'),
(735, 5, 18.00, '2025-11-20 08:45:00'),
(736, 5, 100.00, '2025-11-20 09:15:00'),
(737, 5, 30.00, '2025-11-20 09:45:00'),
(738, 5, 80.00, '2025-11-20 10:15:00'),
(739, 5, 12.00, '2025-11-20 10:45:00'),
(740, 5, 100.00, '2025-11-20 11:15:00'),
(741, 5, 45.00, '2025-11-20 11:45:00'),
(742, 5, 25.00, '2025-11-20 12:15:00'),
(743, 5, 75.00, '2025-11-21 06:05:00'),
(744, 5, 90.00, '2025-11-21 06:35:00'),
(745, 5, 24.00, '2025-11-21 07:05:00'),
(746, 5, 60.00, '2025-11-21 07:35:00'),
(747, 5, 20.00, '2025-11-21 08:05:00'),
(748, 5, 54.00, '2025-11-21 08:35:00'),
(749, 5, 80.00, '2025-11-21 09:05:00'),
(750, 5, 15.00, '2025-11-21 09:35:00'),
(751, 5, 40.00, '2025-11-21 10:05:00'),
(752, 5, 12.00, '2025-11-21 10:35:00'),
(753, 5, 60.00, '2025-11-21 11:05:00'),
(754, 5, 60.00, '2025-11-21 11:35:00'),
(755, 5, 50.00, '2025-11-21 12:05:00'),
(756, 5, 45.00, '2025-11-22 06:15:00'),
(757, 5, 75.00, '2025-11-22 06:45:00'),
(758, 5, 60.00, '2025-11-22 07:15:00'),
(759, 5, 90.00, '2025-11-22 07:45:00'),
(760, 5, 24.00, '2025-11-22 08:15:00'),
(761, 5, 72.00, '2025-11-22 08:45:00'),
(762, 5, 120.00, '2025-11-22 09:15:00'),
(763, 5, 45.00, '2025-11-22 09:45:00'),
(764, 5, 80.00, '2025-11-22 10:15:00'),
(765, 5, 24.00, '2025-11-22 10:45:00'),
(766, 5, 100.00, '2025-11-22 11:15:00'),
(767, 5, 60.00, '2025-11-22 11:45:00'),
(768, 5, 25.00, '2025-11-22 12:15:00'),
(769, 5, 60.00, '2025-11-23 06:05:00'),
(770, 5, 90.00, '2025-11-23 06:35:00'),
(771, 5, 48.00, '2025-11-23 07:05:00'),
(772, 5, 60.00, '2025-11-23 07:35:00'),
(773, 5, 40.00, '2025-11-23 08:05:00'),
(774, 5, 36.00, '2025-11-23 08:45:00'),
(775, 5, 150.00, '2025-11-23 09:15:00'),
(776, 5, 60.00, '2025-11-23 09:45:00'),
(777, 5, 120.00, '2025-11-23 10:15:00'),
(778, 5, 12.00, '2025-11-23 10:45:00'),
(779, 5, 40.00, '2025-11-23 11:15:00'),
(780, 5, 75.00, '2025-11-23 11:45:00'),
(781, 5, 25.00, '2025-11-23 12:15:00'),
(782, 5, 30.00, '2025-11-24 06:05:00'),
(783, 5, 75.00, '2025-11-24 06:35:00'),
(784, 5, 48.00, '2025-11-24 07:05:00'),
(785, 5, 90.00, '2025-11-24 07:35:00'),
(786, 5, 20.00, '2025-11-24 08:05:00'),
(787, 5, 72.00, '2025-11-24 08:35:00'),
(788, 5, 50.00, '2025-11-24 09:05:00'),
(789, 5, 75.00, '2025-11-24 09:35:00'),
(790, 5, 40.00, '2025-11-24 10:05:00'),
(791, 5, 48.00, '2025-11-24 10:35:00'),
(792, 5, 60.00, '2025-11-24 11:05:00'),
(793, 5, 45.00, '2025-11-24 11:35:00'),
(794, 5, 75.00, '2025-11-24 12:05:00'),
(795, 5, 75.00, '2025-11-25 06:15:00'),
(796, 5, 45.00, '2025-11-25 06:45:00'),
(797, 5, 36.00, '2025-11-25 07:15:00'),
(798, 5, 60.00, '2025-11-25 07:45:00'),
(799, 5, 20.00, '2025-11-25 08:15:00'),
(800, 5, 18.00, '2025-11-25 08:45:00'),
(801, 5, 100.00, '2025-11-25 09:15:00'),
(802, 5, 30.00, '2025-11-25 09:45:00'),
(803, 5, 80.00, '2025-11-25 10:15:00'),
(804, 5, 12.00, '2025-11-25 10:45:00'),
(805, 5, 100.00, '2025-11-25 11:15:00'),
(806, 5, 45.00, '2025-11-25 11:45:00'),
(807, 5, 25.00, '2025-11-25 12:15:00'),
(808, 6, 24.00, '2025-10-26 06:25:00'),
(809, 6, 40.00, '2025-10-26 06:55:00'),
(810, 6, 60.00, '2025-10-26 07:25:00'),
(811, 6, 50.00, '2025-10-26 07:55:00'),
(812, 6, 75.00, '2025-10-26 08:25:00'),
(813, 6, 90.00, '2025-10-26 08:55:00'),
(814, 6, 48.00, '2025-10-26 09:25:00'),
(815, 6, 60.00, '2025-10-26 09:55:00'),
(816, 6, 30.00, '2025-10-26 10:25:00'),
(817, 6, 54.00, '2025-10-26 10:55:00'),
(818, 6, 80.00, '2025-10-26 11:25:00'),
(819, 6, 60.00, '2025-10-26 11:55:00'),
(820, 6, 40.00, '2025-10-26 12:25:00'),
(821, 6, 48.00, '2025-10-27 06:15:00'),
(822, 6, 20.00, '2025-10-27 06:45:00'),
(823, 6, 45.00, '2025-10-27 07:15:00'),
(824, 6, 25.00, '2025-10-27 07:45:00'),
(825, 6, 60.00, '2025-10-27 08:15:00'),
(826, 6, 45.00, '2025-10-27 08:45:00'),
(827, 6, 36.00, '2025-10-27 09:15:00'),
(828, 6, 90.00, '2025-10-27 09:45:00'),
(829, 6, 20.00, '2025-10-27 10:15:00'),
(830, 6, 36.00, '2025-10-27 10:45:00'),
(831, 6, 80.00, '2025-10-27 11:15:00'),
(832, 6, 45.00, '2025-10-27 11:45:00'),
(833, 6, 80.00, '2025-10-27 12:15:00'),
(834, 6, 36.00, '2025-10-28 06:05:00'),
(835, 6, 100.00, '2025-10-28 06:35:00'),
(836, 6, 30.00, '2025-10-28 07:05:00'),
(837, 6, 25.00, '2025-10-28 07:35:00'),
(838, 6, 30.00, '2025-10-28 08:05:00'),
(839, 6, 75.00, '2025-10-28 08:35:00'),
(840, 6, 36.00, '2025-10-28 09:05:00'),
(841, 6, 30.00, '2025-10-28 09:35:00'),
(842, 6, 30.00, '2025-10-28 10:05:00'),
(843, 6, 18.00, '2025-10-28 10:35:00'),
(844, 6, 50.00, '2025-10-28 11:05:00'),
(845, 6, 60.00, '2025-10-28 11:35:00'),
(846, 6, 80.00, '2025-10-28 12:05:00'),
(847, 6, 24.00, '2025-10-29 06:15:00'),
(848, 6, 40.00, '2025-10-29 06:45:00'),
(849, 6, 60.00, '2025-10-29 07:15:00'),
(850, 6, 50.00, '2025-10-29 07:45:00'),
(851, 6, 60.00, '2025-10-29 08:15:00'),
(852, 6, 90.00, '2025-10-29 08:45:00'),
(853, 6, 48.00, '2025-10-29 09:15:00'),
(854, 6, 30.00, '2025-10-29 09:45:00'),
(855, 6, 30.00, '2025-10-29 10:15:00'),
(856, 6, 54.00, '2025-10-29 10:45:00'),
(857, 6, 80.00, '2025-10-29 11:15:00'),
(858, 6, 60.00, '2025-10-29 11:45:00'),
(859, 6, 40.00, '2025-10-29 12:15:00'),
(860, 6, 48.00, '2025-10-30 06:05:00'),
(861, 6, 60.00, '2025-10-30 06:35:00'),
(862, 6, 30.00, '2025-10-30 07:05:00'),
(863, 6, 50.00, '2025-10-30 07:35:00'),
(864, 6, 45.00, '2025-10-30 08:05:00'),
(865, 6, 60.00, '2025-10-30 08:35:00'),
(866, 6, 24.00, '2025-10-30 09:05:00'),
(867, 6, 60.00, '2025-10-30 09:35:00'),
(868, 6, 24.00, '2025-10-30 10:05:00'),
(869, 6, 36.00, '2025-10-30 10:35:00'),
(870, 6, 100.00, '2025-10-30 11:05:00'),
(871, 6, 30.00, '2025-10-30 11:35:00'),
(872, 6, 80.00, '2025-10-30 12:05:00'),
(873, 6, 36.00, '2025-10-31 06:15:00'),
(874, 6, 20.00, '2025-10-31 06:45:00'),
(875, 6, 45.00, '2025-10-31 07:15:00'),
(876, 6, 25.00, '2025-10-31 07:45:00'),
(877, 6, 60.00, '2025-10-31 08:15:00'),
(878, 6, 60.00, '2025-10-31 08:45:00'),
(879, 6, 48.00, '2025-10-31 09:15:00'),
(880, 6, 90.00, '2025-10-31 09:45:00'),
(881, 6, 30.00, '2025-10-31 10:15:00'),
(882, 6, 36.00, '2025-10-31 10:45:00'),
(883, 6, 50.00, '2025-10-31 11:15:00'),
(884, 6, 30.00, '2025-10-31 11:45:00'),
(885, 6, 40.00, '2025-10-31 12:15:00'),
(886, 6, 48.00, '2025-11-01 06:25:00'),
(887, 6, 40.00, '2025-11-01 06:55:00'),
(888, 6, 45.00, '2025-11-01 07:25:00'),
(889, 6, 50.00, '2025-11-01 07:55:00'),
(890, 6, 75.00, '2025-11-01 08:25:00'),
(891, 6, 90.00, '2025-11-01 08:55:00'),
(892, 6, 60.00, '2025-11-01 09:25:00'),
(893, 6, 60.00, '2025-11-01 09:55:00'),
(894, 6, 40.00, '2025-11-01 10:25:00'),
(895, 6, 18.00, '2025-11-01 10:55:00'),
(896, 6, 50.00, '2025-11-01 11:25:00'),
(897, 6, 30.00, '2025-11-01 11:55:00'),
(898, 6, 80.00, '2025-11-01 12:25:00'),
(899, 6, 60.00, '2025-11-02 06:25:00'),
(900, 6, 100.00, '2025-11-02 06:55:00'),
(901, 6, 75.00, '2025-11-02 07:25:00'),
(902, 6, 75.00, '2025-11-02 07:55:00'),
(903, 6, 75.00, '2025-11-02 08:25:00'),
(904, 6, 60.00, '2025-11-02 08:55:00'),
(905, 6, 60.00, '2025-11-02 09:25:00'),
(906, 6, 30.00, '2025-11-02 09:55:00'),
(907, 6, 20.00, '2025-11-02 10:25:00'),
(908, 6, 72.00, '2025-11-02 10:55:00'),
(909, 6, 80.00, '2025-11-02 11:25:00'),
(910, 6, 15.00, '2025-11-02 11:55:00'),
(911, 6, 40.00, '2025-11-02 12:25:00'),
(912, 6, 48.00, '2025-11-03 06:15:00'),
(913, 6, 100.00, '2025-11-03 06:45:00'),
(914, 6, 30.00, '2025-11-03 07:15:00'),
(915, 6, 25.00, '2025-11-03 07:45:00'),
(916, 6, 30.00, '2025-11-03 08:15:00'),
(917, 6, 75.00, '2025-11-03 08:45:00'),
(918, 6, 36.00, '2025-11-03 09:15:00'),
(919, 6, 30.00, '2025-11-03 09:45:00'),
(920, 6, 30.00, '2025-11-03 10:15:00'),
(921, 6, 18.00, '2025-11-03 10:45:00'),
(922, 6, 50.00, '2025-11-03 11:15:00'),
(923, 6, 60.00, '2025-11-03 11:45:00'),
(924, 6, 80.00, '2025-11-03 12:15:00'),
(925, 6, 36.00, '2025-11-04 06:25:00'),
(926, 6, 20.00, '2025-11-04 06:55:00'),
(927, 6, 45.00, '2025-11-04 07:25:00'),
(928, 6, 25.00, '2025-11-04 07:55:00'),
(929, 6, 60.00, '2025-11-04 08:25:00'),
(930, 6, 60.00, '2025-11-04 08:55:00'),
(931, 6, 48.00, '2025-11-04 09:25:00'),
(932, 6, 90.00, '2025-11-04 09:55:00'),
(933, 6, 30.00, '2025-11-04 10:25:00'),
(934, 6, 36.00, '2025-11-04 10:55:00'),
(935, 6, 50.00, '2025-11-04 11:25:00'),
(936, 6, 30.00, '2025-11-04 11:55:00'),
(937, 6, 40.00, '2025-11-04 12:25:00'),
(938, 6, 48.00, '2025-11-05 06:15:00'),
(939, 6, 60.00, '2025-11-05 06:45:00'),
(940, 6, 30.00, '2025-11-05 07:15:00'),
(941, 6, 50.00, '2025-11-05 07:45:00'),
(942, 6, 45.00, '2025-11-05 08:15:00'),
(943, 6, 60.00, '2025-11-05 08:45:00'),
(944, 6, 24.00, '2025-11-05 09:15:00'),
(945, 6, 60.00, '2025-11-05 09:45:00'),
(946, 6, 24.00, '2025-11-05 10:15:00'),
(947, 6, 36.00, '2025-11-05 10:45:00'),
(948, 6, 100.00, '2025-11-05 11:15:00'),
(949, 6, 30.00, '2025-11-05 11:45:00'),
(950, 6, 80.00, '2025-11-05 12:15:00'),
(951, 6, 24.00, '2025-11-06 06:25:00'),
(952, 6, 40.00, '2025-11-06 06:55:00'),
(953, 6, 60.00, '2025-11-06 07:25:00'),
(954, 6, 50.00, '2025-11-06 07:55:00'),
(955, 6, 60.00, '2025-11-06 08:25:00'),
(956, 6, 90.00, '2025-11-06 08:55:00'),
(957, 6, 48.00, '2025-11-06 09:25:00'),
(958, 6, 30.00, '2025-11-06 09:55:00'),
(959, 6, 30.00, '2025-11-06 10:25:00'),
(960, 6, 54.00, '2025-11-06 10:55:00'),
(961, 6, 80.00, '2025-11-06 11:25:00'),
(962, 6, 60.00, '2025-11-06 11:55:00'),
(963, 6, 40.00, '2025-11-06 12:25:00'),
(964, 6, 36.00, '2025-11-07 06:15:00'),
(965, 6, 20.00, '2025-11-07 06:45:00'),
(966, 6, 45.00, '2025-11-07 07:15:00'),
(967, 6, 25.00, '2025-11-07 07:45:00'),
(968, 6, 60.00, '2025-11-07 08:15:00'),
(969, 6, 60.00, '2025-11-07 08:45:00'),
(970, 6, 48.00, '2025-11-07 09:15:00'),
(971, 6, 90.00, '2025-11-07 09:45:00'),
(972, 6, 30.00, '2025-11-07 10:15:00'),
(973, 6, 36.00, '2025-11-07 10:45:00'),
(974, 6, 50.00, '2025-11-07 11:15:00'),
(975, 6, 30.00, '2025-11-07 11:45:00'),
(976, 6, 40.00, '2025-11-07 12:15:00'),
(977, 6, 48.00, '2025-11-08 06:25:00'),
(978, 6, 40.00, '2025-11-08 06:55:00'),
(979, 6, 45.00, '2025-11-08 07:25:00'),
(980, 6, 50.00, '2025-11-08 07:55:00'),
(981, 6, 75.00, '2025-11-08 08:25:00'),
(982, 6, 90.00, '2025-11-08 08:55:00'),
(983, 6, 60.00, '2025-11-08 09:25:00'),
(984, 6, 60.00, '2025-11-08 09:55:00'),
(985, 6, 40.00, '2025-11-08 10:25:00'),
(986, 6, 18.00, '2025-11-08 10:55:00'),
(987, 6, 50.00, '2025-11-08 11:25:00'),
(988, 6, 30.00, '2025-11-08 11:55:00'),
(989, 6, 80.00, '2025-11-08 12:25:00'),
(990, 6, 60.00, '2025-11-09 06:25:00'),
(991, 6, 100.00, '2025-11-09 06:55:00'),
(992, 6, 75.00, '2025-11-09 07:25:00'),
(993, 6, 75.00, '2025-11-09 07:55:00'),
(994, 6, 75.00, '2025-11-09 08:25:00'),
(995, 6, 60.00, '2025-11-09 08:55:00'),
(996, 6, 60.00, '2025-11-09 09:25:00'),
(997, 6, 30.00, '2025-11-09 09:55:00'),
(998, 6, 20.00, '2025-11-09 10:25:00'),
(999, 6, 72.00, '2025-11-09 10:55:00'),
(1000, 6, 80.00, '2025-11-09 11:25:00'),
(1001, 6, 15.00, '2025-11-09 11:55:00'),
(1002, 6, 40.00, '2025-11-09 12:25:00'),
(1003, 6, 48.00, '2025-11-10 06:15:00'),
(1004, 6, 100.00, '2025-11-10 06:45:00'),
(1005, 6, 30.00, '2025-11-10 07:15:00'),
(1006, 6, 25.00, '2025-11-10 07:45:00'),
(1007, 6, 30.00, '2025-11-10 08:15:00'),
(1008, 6, 75.00, '2025-11-10 08:45:00'),
(1009, 6, 36.00, '2025-11-10 09:15:00'),
(1010, 6, 30.00, '2025-11-10 09:45:00'),
(1011, 6, 30.00, '2025-11-10 10:15:00'),
(1012, 6, 18.00, '2025-11-10 10:45:00'),
(1013, 6, 50.00, '2025-11-10 11:15:00'),
(1014, 6, 60.00, '2025-11-10 11:45:00'),
(1015, 6, 80.00, '2025-11-10 12:15:00'),
(1016, 6, 24.00, '2025-11-11 06:25:00'),
(1017, 6, 40.00, '2025-11-11 06:55:00'),
(1018, 6, 60.00, '2025-11-11 07:25:00'),
(1019, 6, 50.00, '2025-11-11 07:55:00'),
(1020, 6, 60.00, '2025-11-11 08:25:00'),
(1021, 6, 90.00, '2025-11-11 08:55:00'),
(1022, 6, 48.00, '2025-11-11 09:25:00'),
(1023, 6, 30.00, '2025-11-11 09:55:00'),
(1024, 6, 30.00, '2025-11-11 10:25:00'),
(1025, 6, 54.00, '2025-11-11 10:55:00'),
(1026, 6, 80.00, '2025-11-11 11:25:00'),
(1027, 6, 60.00, '2025-11-11 11:55:00'),
(1028, 6, 40.00, '2025-11-11 12:25:00'),
(1029, 6, 48.00, '2025-11-12 06:15:00'),
(1030, 6, 60.00, '2025-11-12 06:45:00'),
(1031, 6, 30.00, '2025-11-12 07:15:00'),
(1032, 6, 50.00, '2025-11-12 07:45:00'),
(1033, 6, 45.00, '2025-11-12 08:15:00'),
(1034, 6, 60.00, '2025-11-12 08:45:00'),
(1035, 6, 24.00, '2025-11-12 09:15:00'),
(1036, 6, 60.00, '2025-11-12 09:45:00'),
(1037, 6, 24.00, '2025-11-12 10:15:00'),
(1038, 6, 36.00, '2025-11-12 10:45:00'),
(1039, 6, 100.00, '2025-11-12 11:15:00'),
(1040, 6, 30.00, '2025-11-12 11:45:00'),
(1041, 6, 80.00, '2025-11-12 12:15:00'),
(1042, 6, 24.00, '2025-11-13 06:25:00'),
(1043, 6, 40.00, '2025-11-13 06:55:00'),
(1044, 6, 60.00, '2025-11-13 07:25:00'),
(1045, 6, 50.00, '2025-11-13 07:55:00'),
(1046, 6, 60.00, '2025-11-13 08:25:00'),
(1047, 6, 90.00, '2025-11-13 08:55:00'),
(1048, 6, 48.00, '2025-11-13 09:25:00'),
(1049, 6, 30.00, '2025-11-13 09:55:00'),
(1050, 6, 30.00, '2025-11-13 10:25:00'),
(1051, 6, 54.00, '2025-11-13 10:55:00'),
(1052, 6, 80.00, '2025-11-13 11:25:00'),
(1053, 6, 60.00, '2025-11-13 11:55:00'),
(1054, 6, 40.00, '2025-11-13 12:25:00'),
(1055, 6, 36.00, '2025-11-14 06:15:00'),
(1056, 6, 20.00, '2025-11-14 06:45:00'),
(1057, 6, 45.00, '2025-11-14 07:15:00'),
(1058, 6, 25.00, '2025-11-14 07:45:00'),
(1059, 6, 60.00, '2025-11-14 08:15:00'),
(1060, 6, 60.00, '2025-11-14 08:45:00'),
(1061, 6, 48.00, '2025-11-14 09:15:00'),
(1062, 6, 90.00, '2025-11-14 09:45:00'),
(1063, 6, 30.00, '2025-11-14 10:15:00'),
(1064, 6, 36.00, '2025-11-14 10:45:00'),
(1065, 6, 50.00, '2025-11-14 11:15:00'),
(1066, 6, 30.00, '2025-11-14 11:45:00'),
(1067, 6, 40.00, '2025-11-14 12:15:00'),
(1068, 6, 48.00, '2025-11-15 06:25:00'),
(1069, 6, 40.00, '2025-11-15 06:55:00'),
(1070, 6, 45.00, '2025-11-15 07:25:00'),
(1071, 6, 50.00, '2025-11-15 07:55:00'),
(1072, 6, 75.00, '2025-11-15 08:25:00'),
(1073, 6, 90.00, '2025-11-15 08:55:00'),
(1074, 6, 60.00, '2025-11-15 09:25:00'),
(1075, 6, 60.00, '2025-11-15 09:55:00'),
(1076, 6, 40.00, '2025-11-15 10:25:00'),
(1077, 6, 18.00, '2025-11-15 10:55:00'),
(1078, 6, 50.00, '2025-11-15 11:25:00'),
(1079, 6, 30.00, '2025-11-15 11:55:00'),
(1080, 6, 80.00, '2025-11-15 12:25:00'),
(1081, 6, 60.00, '2025-11-16 06:25:00'),
(1082, 6, 100.00, '2025-11-16 06:55:00'),
(1083, 6, 75.00, '2025-11-16 07:25:00'),
(1084, 6, 75.00, '2025-11-16 07:55:00'),
(1085, 6, 75.00, '2025-11-16 08:25:00'),
(1086, 6, 60.00, '2025-11-16 08:55:00'),
(1087, 6, 60.00, '2025-11-16 09:25:00'),
(1088, 6, 30.00, '2025-11-16 09:55:00'),
(1089, 6, 20.00, '2025-11-16 10:25:00'),
(1090, 6, 72.00, '2025-11-16 10:55:00'),
(1091, 6, 80.00, '2025-11-16 11:25:00'),
(1092, 6, 15.00, '2025-11-16 11:55:00'),
(1093, 6, 40.00, '2025-11-16 12:25:00'),
(1094, 6, 48.00, '2025-11-17 06:15:00'),
(1095, 6, 100.00, '2025-11-17 06:45:00'),
(1096, 6, 30.00, '2025-11-17 07:15:00'),
(1097, 6, 25.00, '2025-11-17 07:45:00'),
(1098, 6, 30.00, '2025-11-17 08:15:00'),
(1099, 6, 75.00, '2025-11-17 08:45:00'),
(1100, 6, 36.00, '2025-11-17 09:15:00'),
(1101, 6, 30.00, '2025-11-17 09:45:00'),
(1102, 6, 30.00, '2025-11-17 10:15:00'),
(1103, 6, 18.00, '2025-11-17 10:45:00'),
(1104, 6, 50.00, '2025-11-17 11:15:00'),
(1105, 6, 60.00, '2025-11-17 11:45:00'),
(1106, 6, 80.00, '2025-11-17 12:15:00'),
(1107, 6, 24.00, '2025-11-18 06:25:00'),
(1108, 6, 40.00, '2025-11-18 06:55:00'),
(1109, 6, 60.00, '2025-11-18 07:25:00'),
(1110, 6, 50.00, '2025-11-18 07:55:00'),
(1111, 6, 60.00, '2025-11-18 08:25:00'),
(1112, 6, 90.00, '2025-11-18 08:55:00'),
(1113, 6, 48.00, '2025-11-18 09:25:00'),
(1114, 6, 30.00, '2025-11-18 09:55:00'),
(1115, 6, 30.00, '2025-11-18 10:25:00'),
(1116, 6, 54.00, '2025-11-18 10:55:00'),
(1117, 6, 80.00, '2025-11-18 11:25:00'),
(1118, 6, 60.00, '2025-11-18 11:55:00'),
(1119, 6, 40.00, '2025-11-18 12:25:00'),
(1120, 6, 48.00, '2025-11-19 06:15:00'),
(1121, 6, 60.00, '2025-11-19 06:45:00'),
(1122, 6, 30.00, '2025-11-19 07:15:00'),
(1123, 6, 50.00, '2025-11-19 07:45:00'),
(1124, 6, 45.00, '2025-11-19 08:15:00'),
(1125, 6, 60.00, '2025-11-19 08:45:00'),
(1126, 6, 24.00, '2025-11-19 09:15:00'),
(1127, 6, 60.00, '2025-11-19 09:45:00'),
(1128, 6, 24.00, '2025-11-19 10:15:00'),
(1129, 6, 36.00, '2025-11-19 10:45:00'),
(1130, 6, 100.00, '2025-11-19 11:15:00'),
(1131, 6, 30.00, '2025-11-19 11:45:00'),
(1132, 6, 80.00, '2025-11-19 12:15:00'),
(1133, 6, 24.00, '2025-11-20 06:25:00'),
(1134, 6, 40.00, '2025-11-20 06:55:00'),
(1135, 6, 60.00, '2025-11-20 07:25:00'),
(1136, 6, 50.00, '2025-11-20 07:55:00'),
(1137, 6, 60.00, '2025-11-20 08:25:00'),
(1138, 6, 90.00, '2025-11-20 08:55:00'),
(1139, 6, 48.00, '2025-11-20 09:25:00'),
(1140, 6, 30.00, '2025-11-20 09:55:00'),
(1141, 6, 30.00, '2025-11-20 10:25:00'),
(1142, 6, 54.00, '2025-11-20 10:55:00'),
(1143, 6, 80.00, '2025-11-20 11:25:00'),
(1144, 6, 60.00, '2025-11-20 11:55:00'),
(1145, 6, 40.00, '2025-11-20 12:25:00'),
(1146, 6, 36.00, '2025-11-21 06:15:00'),
(1147, 6, 20.00, '2025-11-21 06:45:00'),
(1148, 6, 45.00, '2025-11-21 07:15:00'),
(1149, 6, 25.00, '2025-11-21 07:45:00'),
(1150, 6, 60.00, '2025-11-21 08:15:00'),
(1151, 6, 60.00, '2025-11-21 08:45:00'),
(1152, 6, 48.00, '2025-11-21 09:15:00'),
(1153, 6, 90.00, '2025-11-21 09:45:00'),
(1154, 6, 30.00, '2025-11-21 10:15:00'),
(1155, 6, 36.00, '2025-11-21 10:45:00'),
(1156, 6, 50.00, '2025-11-21 11:15:00'),
(1157, 6, 30.00, '2025-11-21 11:45:00'),
(1158, 6, 40.00, '2025-11-21 12:15:00'),
(1159, 6, 48.00, '2025-11-22 06:25:00'),
(1160, 6, 40.00, '2025-11-22 06:55:00'),
(1161, 6, 45.00, '2025-11-22 07:25:00'),
(1162, 6, 50.00, '2025-11-22 07:55:00'),
(1163, 6, 75.00, '2025-11-22 08:25:00'),
(1164, 6, 90.00, '2025-11-22 08:55:00'),
(1165, 6, 60.00, '2025-11-22 09:25:00'),
(1166, 6, 60.00, '2025-11-22 09:55:00'),
(1167, 6, 40.00, '2025-11-22 10:25:00'),
(1168, 6, 18.00, '2025-11-22 10:55:00'),
(1169, 6, 50.00, '2025-11-22 11:25:00'),
(1170, 6, 30.00, '2025-11-22 11:55:00'),
(1171, 6, 80.00, '2025-11-22 12:25:00'),
(1172, 6, 60.00, '2025-11-23 06:15:00'),
(1173, 6, 100.00, '2025-11-23 06:45:00'),
(1174, 6, 75.00, '2025-11-23 07:15:00'),
(1175, 6, 75.00, '2025-11-23 07:45:00'),
(1176, 6, 75.00, '2025-11-23 08:25:00'),
(1177, 6, 60.00, '2025-11-23 08:55:00'),
(1178, 6, 60.00, '2025-11-23 09:25:00'),
(1179, 6, 30.00, '2025-11-23 09:55:00'),
(1180, 6, 20.00, '2025-11-23 10:25:00'),
(1181, 6, 72.00, '2025-11-23 10:55:00'),
(1182, 6, 80.00, '2025-11-23 11:25:00'),
(1183, 6, 15.00, '2025-11-23 11:55:00'),
(1184, 6, 40.00, '2025-11-23 12:25:00'),
(1185, 6, 48.00, '2025-11-24 06:15:00'),
(1186, 6, 100.00, '2025-11-24 06:45:00'),
(1187, 6, 30.00, '2025-11-24 07:15:00'),
(1188, 6, 25.00, '2025-11-24 07:45:00'),
(1189, 6, 30.00, '2025-11-24 08:15:00'),
(1190, 6, 75.00, '2025-11-24 08:45:00'),
(1191, 6, 36.00, '2025-11-24 09:15:00'),
(1192, 6, 30.00, '2025-11-24 09:45:00'),
(1193, 6, 30.00, '2025-11-24 10:15:00'),
(1194, 6, 18.00, '2025-11-24 10:45:00'),
(1195, 6, 50.00, '2025-11-24 11:15:00'),
(1196, 6, 60.00, '2025-11-24 11:45:00'),
(1197, 6, 80.00, '2025-11-24 12:15:00'),
(1198, 6, 24.00, '2025-11-25 06:25:00'),
(1199, 6, 40.00, '2025-11-25 06:55:00'),
(1200, 6, 60.00, '2025-11-25 07:25:00'),
(1201, 6, 50.00, '2025-11-25 07:55:00'),
(1202, 6, 60.00, '2025-11-25 08:25:00'),
(1203, 6, 90.00, '2025-11-25 08:55:00'),
(1204, 6, 48.00, '2025-11-25 09:25:00'),
(1205, 6, 30.00, '2025-11-25 09:55:00'),
(1206, 6, 30.00, '2025-11-25 10:25:00'),
(1207, 6, 54.00, '2025-11-25 10:55:00'),
(1208, 6, 80.00, '2025-11-25 11:25:00'),
(1209, 6, 60.00, '2025-11-25 11:55:00'),
(1210, 6, 40.00, '2025-11-25 12:25:00'),
(2048, 3, 148.00, '2025-11-25 20:23:01'),
(2049, 3, 138.60, '2025-11-25 20:23:11'),
(2050, 3, 126.90, '2025-11-25 20:23:46');

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`product_id`, `name`, `price`, `image_url`, `status`, `stock_qty`, `stock_unit`, `is_sellable`, `batch_size`) VALUES
(1, 'Spanish Bread', 10.00, NULL, 'available', 47, 'pcs', 1, 24),
(2, 'Cheese Bread', 12.00, '../uploads/products/prod_6912d9f7dd8542.44340855.jpg', 'available', 20, 'pcs', 1, 25),
(3, 'Ensaymada', 15.00, NULL, 'available', 22, 'pcs', 1, 12),
(4, 'Cinnamon Roll', 20.00, NULL, 'available', 17, 'pcs', 1, 12),
(5, 'Choco Bread', 12.00, '../uploads/products/prod_6912dc4b078fa3.44458814.jpg', 'available', 52, 'pcs', 1, 24),
(6, 'Ube Cheese Pandesal', 15.00, NULL, 'available', 35, 'pcs', 1, 30),
(7, 'Hotdog Roll', 18.00, NULL, 'available', 14, 'pcs', 1, 1),
(8, 'Cheese Stick Bread', 10.00, '../uploads/products/prod_6912da69d6bf68.30804241.jpg', 'available', 27, 'pcs', 1, 1),
(9, 'Tuna Bun', 20.00, NULL, 'available', 15, 'pcs', 1, 1),
(10, 'Egg Pie Slice', 25.00, NULL, 'available', 6, 'pcs', 1, 1),
(11, 'Mocha Bun', 12.00, NULL, 'available', 34, 'pcs', 1, 1),
(12, 'Corned Beef Bread', 20.00, NULL, 'available', 16, 'pcs', 1, 1),
(13, 'Chicken Floss Bun', 25.00, '../uploads/products/prod_6912dc2989ccb1.13733051.jpg', 'available', 30, 'pcs', 1, 1),
(14, 'Chocolate Donut', 18.00, NULL, 'available', 28, 'pcs', 1, 1),
(16, 'Cream Bread', 10.00, NULL, 'discontinued', 48, 'pcs', 1, 1),
(17, 'Coffee Bun', 15.00, NULL, 'available', 32, 'pcs', 1, 1),
(18, 'Garlic Bread', 12.00, NULL, 'available', 29, 'pcs', 1, 1),
(19, 'Milky Loaf', 35.00, NULL, 'available', 19, 'loaf', 1, 1),
(20, 'Whole Wheat Loaf', 40.00, NULL, 'available', 14, 'loaf', 1, 1),
(21, 'Raisin Bread', 20.00, NULL, 'available', 15, 'pcs', 1, 1),
(22, 'Banana Loaf', 35.00, '../uploads/products/prod_6912da909c02d2.10638207.jpg', 'available', 2, 'loaf', 1, 1),
(23, 'Cheese Cupcake', 15.00, '../uploads/products/prod_6912da1b2c3e28.82878651.jpg', 'available', 19, 'pcs', 1, 1),
(24, 'Butter Muffin', 18.00, '../uploads/products/prod_6912d9d87feab5.21908923.jpg', 'available', 5, 'pcs', 1, 1),
(25, 'Yema Bread', 12.00, NULL, 'available', 30, 'pcs', 1, 1),
(26, 'Chocolate Crinkles', 10.00, '../uploads/products/prod_6912dce63c5352.95058142.jpg', 'available', 24, 'pcs', 1, 1),
(27, 'Pan de Coco', 12.00, NULL, 'available', 0, 'pcs', 1, 1),
(28, 'Baguette', 30.00, '../uploads/products/prod_6912d96d877162.80266620.jpg', 'available', 8, 'pcs', 1, 1),
(29, 'Focaccia Bread', 28.00, NULL, 'available', 1, 'pcs', 1, 1),
(30, 'Mini Donut', 8.00, NULL, 'available', 30, 'pcs', 1, 1),
(31, 'Pandesal', 2.00, '../uploads/products/prod_691b0d3c93d025.68910842.jpg', 'available', 10, 'pcs', 1, 1);

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `returns`
--

INSERT INTO `returns` (`return_id`, `sale_id`, `product_id`, `user_id`, `qty_returned`, `return_value`, `reason`, `timestamp`) VALUES
(1, 16, 14, 3, 1, 16.20, 'Spoiled Item', '2025-11-25 20:24:27');

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `sale_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `qty_sold` int(11) DEFAULT NULL,
  `total_price` decimal(10,2) DEFAULT NULL,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `qty_returned` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`sale_id`, `order_id`, `product_id`, `qty_sold`, `total_price`, `discount_percent`, `qty_returned`) VALUES
(1, 2048, 3, 2, 30.00, 0.00, 0),
(2, 2048, 4, 2, 40.00, 0.00, 0),
(3, 2048, 13, 1, 25.00, 0.00, 0),
(4, 2048, 8, 1, 10.00, 0.00, 0),
(5, 2048, 14, 1, 18.00, 0.00, 0),
(6, 2048, 10, 1, 25.00, 0.00, 0),
(7, 2049, 29, 2, 50.40, 10.00, 0),
(8, 2049, 17, 1, 13.50, 10.00, 0),
(9, 2049, 13, 1, 22.50, 10.00, 0),
(10, 2049, 3, 1, 13.50, 10.00, 0),
(11, 2049, 10, 1, 22.50, 10.00, 0),
(12, 2049, 7, 1, 16.20, 10.00, 0),
(13, 2050, 4, 1, 18.00, 10.00, 0),
(14, 2050, 13, 1, 22.50, 10.00, 0),
(15, 2050, 8, 1, 9.00, 10.00, 0),
(16, 2050, 14, 1, 16.20, 10.00, 1),
(17, 2050, 24, 1, 16.20, 10.00, 0),
(18, 2050, 2, 1, 10.80, 10.00, 0),
(19, 2050, 29, 2, 50.40, 10.00, 0);

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password`, `role`, `email`, `phone_number`, `enable_daily_report`, `created_at`) VALUES
(2, 'klain123', '$2y$10$pS2IpgUKXqAaSGO3oqgSHOnVJ0CS3FHy6f0nrDxFj6iapGe3FeTne', 'cashier', 'dreedklaingonito@gmail.com', '09923142756', 0, '2025-10-20 04:44:55'),
(3, 'gian123', '$2y$10$m5.v4iXP80yrem7r/u6TSOmaVlvN.Nt8mgM.ggT01shMpL/gYy7j2', 'manager', 'givano550@gmail.com', '09945005100', 0, '2025-10-20 05:50:57'),
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

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `view_activelowstockalerts`  AS SELECT `a`.`alert_id` AS `alert_id`, `a`.`ingredient_id` AS `ingredient_id`, `i`.`name` AS `ingredient_name`, `i`.`stock_qty` AS `current_stock`, `i`.`reorder_level` AS `reorder_level`, `a`.`message` AS `message`, `a`.`date_triggered` AS `date_triggered` FROM (`alerts` `a` join `ingredients` `i` on(`a`.`ingredient_id` = `i`.`ingredient_id`)) WHERE `a`.`status` = 'unread' ;

-- --------------------------------------------------------

--
-- Structure for view `view_discontinuedproducts`
--
DROP TABLE IF EXISTS `view_discontinuedproducts`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `view_discontinuedproducts`  AS SELECT `products`.`product_id` AS `product_id`, `products`.`name` AS `name`, `products`.`price` AS `price`, `products`.`stock_qty` AS `stock_qty`, `products`.`status` AS `status`, `products`.`stock_unit` AS `stock_unit`, `products`.`is_sellable` AS `is_sellable` FROM `products` WHERE `products`.`status` = 'discontinued' ORDER BY `products`.`name` ASC ;

-- --------------------------------------------------------

--
-- Structure for view `view_ingredientstocklevel`
--
DROP TABLE IF EXISTS `view_ingredientstocklevel`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `view_ingredientstocklevel`  AS SELECT `i`.`ingredient_id` AS `ingredient_id`, `i`.`name` AS `name`, `i`.`unit` AS `unit`, coalesce(sum(`ib`.`quantity`),0) AS `stock_qty`, `i`.`reorder_level` AS `reorder_level`, coalesce(sum(`ib`.`quantity`),0) - `i`.`reorder_level` AS `stock_surplus` FROM (`ingredients` `i` left join `ingredient_batches` `ib` on(`i`.`ingredient_id` = `ib`.`ingredient_id`)) GROUP BY `i`.`ingredient_id` ;

-- --------------------------------------------------------

--
-- Structure for view `view_productinventory`
--
DROP TABLE IF EXISTS `view_productinventory`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `view_productinventory`  AS SELECT `p`.`product_id` AS `product_id`, `p`.`name` AS `name`, `p`.`price` AS `price`, `p`.`image_url` AS `image_url`, `p`.`stock_qty` AS `stock_qty`, `p`.`status` AS `status`, `p`.`stock_unit` AS `stock_unit`, `p`.`is_sellable` AS `is_sellable` FROM `products` AS `p` WHERE `p`.`status` in ('available','recalled') ;

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
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`order_id`),
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
  ADD PRIMARY KEY (`sale_id`),
  ADD KEY `order_id_idx` (`order_id`);

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
  ADD UNIQUE KEY `phone_number` (`phone_number`),
  ADD UNIQUE KEY `email` (`email`);

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
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=76;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `order_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2051;

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
  MODIFY `return_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `sale_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

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
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_order_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `fk_sale_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_adjustments`
--
ALTER TABLE `stock_adjustments`
  ADD CONSTRAINT `fk_stock_adjustments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
