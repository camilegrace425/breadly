-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Dec 13, 2025 at 01:10 PM
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
CREATE DEFINER=`root`@`localhost` PROCEDURE `AdminDeleteUser` (IN `p_user_id` INT)   BEGIN
    DELETE FROM users WHERE user_id = p_user_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `AdminGetAllUsers` ()   BEGIN
    SELECT user_id, username, role, email, phone_number, created_at 
    FROM users 
    ORDER BY role, username;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `AdminGetManagers` ()   BEGIN
    SELECT user_id, username, phone_number
    FROM users
    WHERE role IN ('manager', 'assistant_manager') 
      AND phone_number IS NOT NULL 
      AND phone_number != '';
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `AdminGetMySettings` (IN `p_user_id` INT)   BEGIN
    SELECT phone_number, enable_daily_report 
    FROM users 
    WHERE user_id = p_user_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `AdminGetUsersForDailyReport` ()   BEGIN
    SELECT phone_number 
    FROM users 
    WHERE 
        role = 'manager' 
        AND enable_daily_report = 1
        AND phone_number IS NOT NULL 
        AND phone_number != '';
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `AdminUpdateMySettings` (IN `p_user_id` INT, IN `p_phone_number` VARCHAR(12), IN `p_enable_report` TINYINT)   BEGIN
    UPDATE users
    SET 
        phone_number = p_phone_number,
        enable_daily_report = p_enable_report
    WHERE user_id = p_user_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `AdminUpdateUser` (IN `p_user_id` INT, IN `p_username` VARCHAR(100), IN `p_password` VARCHAR(255), IN `p_role` ENUM('manager','cashier','assistant_manager'), IN `p_email` VARCHAR(150), IN `p_phone` VARCHAR(11))   BEGIN
    UPDATE users
    SET 
        username = p_username,
        password = IF(p_password IS NOT NULL AND p_password != '', p_password, password),
        role = p_role,
        email = p_email,
        phone_number = p_phone
    WHERE user_id = p_user_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `DashboardGetActiveLowStockAlerts` (IN `p_limit` INT)   BEGIN
    SELECT * FROM view_ActiveLowStockAlerts
    ORDER BY current_stock ASC
    LIMIT p_limit;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `DashboardGetLowStockAlertsCount` ()   BEGIN
    SELECT COUNT(*) AS alertCount FROM view_ActiveLowStockAlerts;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `DashboardGetRecalledStockValue` (IN `p_date_start` DATE, IN `p_date_end` DATE)   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `DashboardGetSalesSummaryByDateRange` (IN `p_date_start` DATE, IN `p_date_end` DATE)   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `IngredientAdd` (IN `name` VARCHAR(100), IN `unit` VARCHAR(50), IN `stock_qty` FLOAT, IN `reorder_level` FLOAT)   BEGIN
    INSERT INTO ingredients(name, unit, stock_qty, reorder_level)
    VALUES (name, unit, stock_qty, reorder_level);

    SELECT LAST_INSERT_ID() AS new_ingredient_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `IngredientAdjustStock` (IN `p_ingredient_id` INT, IN `p_user_id` INT, IN `p_adjustment_qty` FLOAT, IN `p_reason` VARCHAR(255), IN `p_expiration_date` DATE)   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `IngredientCheckLowStock` ()   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `IngredientDelete` (IN `p_ingredient_id` INT, OUT `p_status` VARCHAR(255))   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `IngredientGetAllSimple` ()   BEGIN
    SELECT ingredient_id, name, unit 
    FROM ingredients 
    ORDER BY name;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `IngredientReduceStockBatchFEFO` (IN `p_ingredient_id` INT, IN `p_qty_to_remove` FLOAT)   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `IngredientUpdate` (IN `p_ingredient_id` INT, IN `p_name` VARCHAR(100), IN `p_unit` VARCHAR(50), IN `p_reorder_level` FLOAT)   BEGIN
    UPDATE ingredients
    SET
        name = p_name,
        unit = p_unit,
        reorder_level = p_reorder_level
    WHERE ingredient_id = p_ingredient_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `InventoryGetDiscontinued` ()   BEGIN
    SELECT * FROM view_DiscontinuedProducts ORDER BY name;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `InventoryGetIngredients` ()   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `InventoryGetProducts` ()   BEGIN
    SELECT * FROM view_ProductInventory ORDER BY name;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `InventoryGetRecallHistory` (IN `p_date_start` DATE, IN `p_date_end` DATE)   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `LogLoginAttempt` (IN `p_user_id` INT, IN `p_username` VARCHAR(100), IN `p_status` ENUM('success','failure'), IN `p_device_type` VARCHAR(50))   BEGIN
    INSERT INTO login_history (user_id, username_attempt, status, device_type)
    VALUES (p_user_id, p_username, p_status, p_device_type);
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `PosGetAvailableProducts` ()   BEGIN
    SELECT product_id, name, price, stock_qty, image_url -- <-- ADDED image_url
    FROM view_ProductInventory
    WHERE status = 'available' AND stock_qty > 0
    ORDER BY name;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `ProductAdd` (IN `p_name` VARCHAR(100), IN `p_price` DECIMAL(10,2), IN `p_image_url` VARCHAR(255))   BEGIN
    INSERT INTO products(name, price, image_url, stock_qty, status) -- <-- ADDED image_url
    VALUES (p_name, p_price, p_image_url, 0, 'available'); -- <-- ADDED p_image_url

    SELECT LAST_INSERT_ID() AS new_product_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `ProductAdjustStock` (IN `p_product_id` INT, IN `p_user_id` INT, IN `p_adjustment_qty` INT, IN `p_reason` VARCHAR(255), OUT `p_status` VARCHAR(255))   BEGIN
    DECLARE v_batch_size INT;
    DECLARE v_num_batches FLOAT;
    DECLARE v_qty_adjusted INT;
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_ing_id INT;
    DECLARE v_qty_needed_base FLOAT;
    DECLARE v_validation_error VARCHAR(255) DEFAULT NULL;
    
    -- Cursor for Recipe Ingredients (Used for deduction)
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

    IF p_adjustment_qty = 0 THEN
        SET p_status = 'Error: Quantity cannot be zero.';
    ELSEIF p_adjustment_qty < 0 AND (LOWER(p_reason) NOT LIKE '%correction%') THEN
         -- Simple Product Removal (Spoilage/Recall)
        UPDATE products SET stock_qty = stock_qty + p_adjustment_qty WHERE product_id = p_product_id;
        INSERT INTO stock_adjustments (item_id, item_type, user_id, adjustment_qty, reason) VALUES (p_product_id, 'product', p_user_id, p_adjustment_qty, p_reason);
        SET p_status = 'Success: Stock removed.';
    ELSE
        -- PRODUCTION or CORRECTION
        SELECT batch_size INTO v_batch_size FROM products WHERE product_id = p_product_id;
        IF v_batch_size = 0 OR v_batch_size IS NULL THEN SET v_batch_size = 1; END IF;
        SET v_num_batches = v_qty_adjusted / v_batch_size;

        -- 1. VALIDATE STOCK FIRST (This block was missing in your original code)
        IF v_num_batches > 0 THEN
            SELECT CONCAT('Error: Insufficient ', i.name, '. Needed: ', ROUND(req.total_needed, 2), ', Stock: ', ROUND(IFNULL(visl.stock_qty, 0), 2))
            INTO v_validation_error
            FROM (
                SELECT 
                    r.ingredient_id,
                    SUM((r.qty_needed * uc_req.to_base_factor / uc_stock.to_base_factor) * v_num_batches) as total_needed
                FROM recipes r
                JOIN ingredients i ON r.ingredient_id = i.ingredient_id
                JOIN unit_conversions uc_req ON r.unit = uc_req.unit
                JOIN unit_conversions uc_stock ON i.unit = uc_stock.unit
                WHERE r.product_id = p_product_id
                GROUP BY r.ingredient_id
            ) req
            JOIN ingredients i ON req.ingredient_id = i.ingredient_id
            LEFT JOIN view_IngredientStockLevel visl ON i.ingredient_id = visl.ingredient_id
            WHERE req.total_needed > IFNULL(visl.stock_qty, 0)
            LIMIT 1;
        END IF;

        -- 2. Transaction (Only run if no validation error)
        IF v_validation_error IS NOT NULL THEN
            SET p_status = v_validation_error;
        ELSE
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
                        -- Correction: Add Ingredient Stock back
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
    END IF;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `ProductDelete` (IN `p_product_id` INT, OUT `p_status` VARCHAR(255))   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `ProductGetAllSimple` ()   BEGIN
    SELECT product_id, name, batch_size
    FROM products 
    WHERE status = 'available' 
    ORDER BY name;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `ProductGetById` (IN `p_product_id` INT)   BEGIN
    SELECT * FROM products WHERE product_id = p_product_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `ProductUpdate` (IN `p_product_id` INT, IN `p_name` VARCHAR(100), IN `p_price` DECIMAL(10,2), IN `p_status` ENUM('available','recalled','discontinued'), IN `p_image_url` VARCHAR(255))   BEGIN
    UPDATE products
    SET
        name = p_name,
        price = p_price,
        status = p_status,
        -- If p_image_url is NULL, keep the old one. If it's a path, update it.
        image_url = IF(p_image_url IS NULL, image_url, p_image_url) 
    WHERE product_id = p_product_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `ProductUpdateBatchSize` (IN `p_product_id` INT, IN `p_batch_size` INT)   BEGIN
    UPDATE products
    SET batch_size = p_batch_size
    WHERE product_id = p_product_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `RecallInitiate` (IN `product_id` INT, IN `reason` TEXT, IN `batch_start_date` DATE, IN `batch_end_date` DATE)   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `RecallLogRemoval` (IN `recall_id` INT, IN `user_id` INT, IN `qty_removed_from_stock` INT, IN `notes` TEXT)   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `RecipeAddIngredient` (IN `p_product_id` INT, IN `p_ingredient_id` INT, IN `p_qty_needed` FLOAT, IN `p_unit` VARCHAR(50))   BEGIN
    -- Check for duplicates first
    IF NOT EXISTS (SELECT 1 FROM recipes WHERE product_id = p_product_id AND ingredient_id = p_ingredient_id) THEN
        INSERT INTO recipes (product_id, ingredient_id, qty_needed, unit)
        VALUES (p_product_id, p_ingredient_id, p_qty_needed, p_unit);
    END IF;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `RecipeGetByProductId` (IN `p_product_id` INT)   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `RecipeRemoveIngredient` (IN `p_recipe_id` INT)   BEGIN
    DELETE FROM recipes WHERE recipe_id = p_recipe_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `ReportGetBestSellers` (IN `date_start` DATE, IN `date_end` DATE)   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `ReportGetLoginHistory` ()   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `ReportGetReturnHistory` ()   BEGIN
    SELECT
        r.sale_id,
        s.order_id,  -- Added Order ID selection
        r.timestamp,
        p.name AS product_name,
        r.qty_returned,
        r.return_value,
        u.username,
        r.reason
    FROM
        `returns` r
    LEFT JOIN
        sales s ON r.sale_id = s.sale_id -- Join sales to get the Order ID
    LEFT JOIN
        products p ON r.product_id = p.product_id
    LEFT JOIN
        users u ON r.user_id = u.user_id
    ORDER BY
        r.timestamp DESC;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `ReportGetSalesHistory` (IN `p_date_start` DATE, IN `p_date_end` DATE, IN `p_sort_column` VARCHAR(50), IN `p_sort_direction` VARCHAR(4))   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `ReportGetSalesSummaryByDate` (IN `p_date_start` DATE, IN `p_date_end` DATE)   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `ReportGetSalesSummaryToday` ()   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `ReportGetStockAdjustmentHistory` ()   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `ReportGetStockAdjustmentHistoryByDate` (IN `p_date_start` DATE, IN `p_date_end` DATE)   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `ReportGetUnsoldProducts` (IN `p_date_start` DATE, IN `p_date_end` DATE)   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `SaleProcessReturn` (IN `p_sale_id` INT, IN `p_user_id` INT, IN `p_return_qty` INT, IN `p_reason` VARCHAR(255))   BEGIN
    DECLARE v_product_id INT;
    DECLARE v_original_qty INT;
    DECLARE v_already_returned INT;
    DECLARE v_max_returnable INT;
    DECLARE v_unit_price DECIMAL(10,2);
    DECLARE v_return_value DECIMAL(10,2);
    DECLARE v_order_id INT;
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
         -- Use the concatenation directly to set the error message
         SET v_error_message = CONCAT('Error: Cannot return more. Only ', v_max_returnable, ' items are available to be returned from this sale.');
         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_error_message;
    END IF;

    -- Calculate the value being "refunded"
    SET v_return_value = v_unit_price * p_return_qty;
    
    START TRANSACTION;

    -- STEP 1: LOG FOR DISPOSAL/RECALL (AS REQUESTED)
    -- This inserts a negative stock adjustment to remove the product from any tracked inventory,
    -- allowing recall/disposal tracking without affecting main product stock (which is achieved by omitting the UPDATE products SET stock_qty...).
    INSERT INTO stock_adjustments (item_id, item_type, user_id, adjustment_qty, reason)
    VALUES (v_product_id, 'product', p_user_id, -p_return_qty, CONCAT('[Return Disposal] ', p_reason));

    -- (The original stock update to the 'products' table is intentionally omitted here.)

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

CREATE DEFINER=`root`@`localhost` PROCEDURE `SaleRecordTransaction` (IN `user_id` INT, IN `product_id` INT, IN `qty_sold` INT, IN `p_discount_percent` DECIMAL(5,2), INOUT `p_order_id` INT, OUT `status` VARCHAR(100), OUT `sale_id` INT)   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `UserCheckAvailability` (IN `p_username` VARCHAR(100), IN `p_email` VARCHAR(150), IN `p_phone` VARCHAR(11))   BEGIN
    SELECT user_id 
    FROM users 
    WHERE username = p_username 
       OR email = p_email 
       -- Only check phone if it's not empty/null to allow multiple users without phones
       OR (p_phone IS NOT NULL AND p_phone != '' AND phone_number = p_phone)
    LIMIT 1;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `UserCheckAvailabilityForUpdate` (IN `p_user_id` INT, IN `p_username` VARCHAR(100) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci, IN `p_email` VARCHAR(150) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci, IN `p_phone` VARCHAR(11) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci)   BEGIN
    SELECT user_id 
    FROM users 
    WHERE (username = p_username OR email = p_email OR (p_phone IS NOT NULL AND p_phone != '' AND phone_number = p_phone))
      AND user_id != p_user_id
    LIMIT 1;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `UserCreateAccount` (IN `p_username` VARCHAR(100), IN `p_hashed_password` VARCHAR(255), IN `p_role` ENUM('manager','cashier','assistant_manager'), IN `p_email` VARCHAR(150), IN `p_phone` VARCHAR(11))   BEGIN
    -- FIX: Changed p_phone_number to p_phone to match the parameter above
    INSERT INTO users (username, password, role, email, phone_number)
    VALUES (p_username, p_hashed_password, p_role, p_email, p_phone);
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `UserFindById` (IN `p_user_id` INT)   BEGIN
    SELECT user_id, username, role, email, phone_number FROM users WHERE user_id = p_user_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `UserFindByPhone` (IN `p_phone_number` VARCHAR(11))   BEGIN
    SELECT * FROM users WHERE phone_number = p_phone_number;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `UserLogin` (IN `p_username` VARCHAR(100) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci)   BEGIN
    SELECT * FROM users WHERE username = p_username;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `UserRequestPasswordReset` (IN `email` VARCHAR(150), OUT `token` VARCHAR(255))   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `UserResetPassword` (IN `p_token_or_otp` VARCHAR(255), IN `p_new_hashed_password` VARCHAR(255))   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `UserStorePhoneOTP` (IN `p_user_id` INT, IN `p_otp_code` VARCHAR(10), IN `p_expiration_time` DATETIME)   BEGIN
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
(6, 'Bread Flour', 'kg', 532, 10),
(7, 'All-Purpose Flour', 'kg', 4287, 10),
(8, 'Sugar (White)', 'kg', 223.95, 5),
(9, 'Sugar (Brown)', 'kg', 10, 2),
(10, 'Salt', 'kg', 4.66, 1),
(11, 'Yeast (Instant)', 'g', 1105, 100),
(12, 'Butter (Unsalted)', 'kg', 849.9, 2),
(13, 'Margarine', 'kg', 9.4, 2),
(14, 'Eggs', 'tray', 64.9333, 2),
(15, 'Water', 'L', 5.95, 5),
(16, 'Full Cream Milk', 'L', 9.5, 3),
(17, 'Evaporated Milk', 'can', 40, 10),
(18, 'Condensed Milk', 'can', 14, 5),
(19, 'Cheddar Cheese', 'kg', 5, 1),
(20, 'Chocolate Chips (Dark)', 'kg', 62.7, 1),
(21, 'Cinnamon Powder', 'g', 250, 50),
(22, 'Ube Halaya', 'kg', 5, 1),
(23, 'Hotdog', 'pack', 30, 10),
(24, 'Tuna (in can)', 'can', 40, 10),
(25, 'Garlic Powder', 'g', 500, 100),
(26, 'Corned Beef (in can)', 'can', 30, 10),
(27, 'Chicken Floss', 'kg', 2, 0.5),
(28, 'Banana', 'kg', 27, 3),
(29, 'Cocoa Powder', 'kg', 2, 0.5),
(30, 'Desiccated Coconut', 'kg', 21.5, 1),
(31, 'Yema Spread', 'kg', 3, 1),
(32, 'Raisins', 'kg', 2, 0.5),
(33, 'Cream Cheese', 'kg', 4, 1),
(34, 'Baking Powder', 'g', 1250, 100),
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
(2, 7, 10.5, '2026-02-13', '2025-11-20'),
(3, 8, 17.575, NULL, '2025-11-20'),
(4, 9, 10, NULL, '2025-11-20'),
(5, 10, 4.62, NULL, '2025-11-20'),
(6, 11, 55, NULL, '2025-11-20'),
(7, 12, 6.26, NULL, '2025-11-20'),
(8, 13, 9.01667, NULL, '2025-11-20'),
(9, 14, 1.06666, NULL, '2025-11-20'),
(10, 15, 4.23333, NULL, '2025-11-20'),
(11, 16, 8, NULL, '2025-11-20'),
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
(24, 29, 2, NULL, '2025-11-20'),
(25, 30, 21.5, NULL, '2025-11-20'),
(26, 31, 3, NULL, '2025-11-20'),
(27, 32, 2, NULL, '2025-11-20'),
(28, 33, 4, NULL, '2025-11-20'),
(29, 34, 200, NULL, '2025-11-20'),
(30, 35, 300, NULL, '2025-11-20'),
(31, 36, 10, NULL, '2025-11-20'),
(32, 37, 1.962, NULL, '2025-11-20'),
(68, 11, 953.333, '2026-01-31', '2025-11-21'),
(69, 7, 10, '2026-03-31', '2025-12-03'),
(70, 7, 497, '2025-12-05', '2025-12-05'),
(71, 20, 60, '2025-12-05', '2025-12-05'),
(72, 28, 1, '2025-12-05', '2025-12-05'),
(73, 12, 239.28, '2025-12-05', '2025-12-05'),
(74, 6, 497.167, '2025-12-05', '2025-12-05'),
(75, 7, 1000, '2025-12-05', '2025-12-05'),
(76, 7, 250, '2025-12-05', '2025-12-05'),
(77, 34, 660, '2025-12-05', '2025-12-05'),
(78, 28, 5, '2025-12-05', '2025-12-05'),
(79, 12, 600, '2025-12-05', '2025-12-05'),
(80, 14, 59.1444, '2025-12-05', '2025-12-05'),
(81, 8, 198.675, '2025-12-05', '2025-12-05'),
(82, 7, 2500, '2025-12-05', '2025-12-05');

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
(18, NULL, 'klain123', 'failure', 'Desktop', '2025-11-17 21:39:14'),
(19, 3, 'gian123', 'success', 'Desktop', '2025-11-17 21:39:18'),
(20, NULL, 'klain123', 'failure', 'Mobile', '2025-11-17 21:43:02'),
(30, NULL, 'klain123', 'failure', 'Mobile', '2025-11-17 21:50:02'),
(31, NULL, 'klain123', 'failure', 'Mobile', '2025-11-17 21:50:18'),
(32, 3, 'gian123', 'success', 'Mobile', '2025-11-17 21:50:31'),
(33, NULL, 'klain123', 'failure', 'Mobile', '2025-11-17 21:50:52'),
(34, NULL, 'klain123', 'failure', 'Mobile', '2025-11-17 21:51:29'),
(35, NULL, 'klain123', 'failure', 'Mobile', '2025-11-17 21:51:38'),
(36, NULL, 'klain123', 'failure', 'Mobile', '2025-11-17 21:51:50'),
(37, NULL, 'klain123', 'failure', 'Mobile', '2025-11-17 21:51:53'),
(38, NULL, 'klain123', 'success', 'Mobile', '2025-11-17 21:51:58'),
(39, NULL, 'klain123', 'failure', 'Mobile', '2025-11-17 21:55:18'),
(40, NULL, 'klain123', 'success', 'Mobile', '2025-11-17 21:58:40'),
(41, 3, 'gian123', 'success', 'Mobile', '2025-11-17 21:58:58'),
(42, 3, 'Gian123', 'success', 'Mobile', '2025-11-17 22:12:26'),
(43, NULL, 'klain123', 'failure', 'Desktop', '2025-11-17 22:26:50'),
(44, NULL, 'klain123', 'success', 'Desktop', '2025-11-17 22:26:53'),
(45, 3, 'gian123', 'success', 'Desktop', '2025-11-17 22:27:32'),
(46, NULL, 'klain123', 'failure', 'Mobile', '2025-11-17 22:30:11'),
(47, NULL, 'klain123', 'success', 'Mobile', '2025-11-17 22:30:25'),
(48, 3, 'gian123', 'success', 'Desktop', '2025-11-17 22:31:04'),
(49, 3, 'gian123', 'success', 'Desktop', '2025-11-17 22:47:48'),
(50, 3, 'Gian123', 'success', 'Mobile', '2025-11-18 00:10:44'),
(51, 3, 'gian123', 'success', 'Mobile', '2025-11-18 00:43:27'),
(52, NULL, 'klain123', 'failure', 'Mobile', '2025-11-18 00:46:56'),
(53, NULL, 'klain123', 'success', 'Mobile', '2025-11-18 00:47:10'),
(54, 3, 'gian123', 'success', 'Desktop', '2025-11-18 00:47:13'),
(55, 3, 'gian123', 'success', 'Desktop', '2025-11-19 20:25:53'),
(56, NULL, 'klain123', 'success', 'Mobile', '2025-11-19 20:27:08'),
(57, NULL, 'klain123', 'success', 'Mobile', '2025-11-19 21:17:30'),
(58, NULL, 'camile123', 'success', 'Desktop', '2025-11-19 22:51:27'),
(59, NULL, 'klain123', 'success', 'Desktop', '2025-11-19 22:55:09'),
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
(75, 3, 'Gian123', 'success', 'Mobile', '2025-11-25 20:36:17'),
(76, 3, 'gian123', 'success', 'Desktop', '2025-11-25 21:54:45'),
(77, 4, 'camile123', 'success', 'Mobile', '2025-11-25 22:00:50'),
(78, 3, 'Gian123', 'success', 'Mobile', '2025-11-25 22:01:13'),
(79, NULL, 'klain123', 'success', 'Desktop', '2025-11-25 22:07:34'),
(80, 3, 'gian123', 'success', 'Desktop', '2025-11-25 22:08:00'),
(81, 3, 'gian123', 'success', 'Desktop', '2025-11-25 22:43:38'),
(82, 3, 'gian123', 'success', 'Mobile', '2025-11-25 22:45:31'),
(83, 3, 'gian123', 'success', 'Desktop', '2025-11-26 08:55:04'),
(84, 4, 'camile123', 'success', 'Desktop', '2025-11-26 11:29:31'),
(85, 3, 'gian123', 'success', 'Desktop', '2025-11-26 13:17:13'),
(86, 4, 'camile123', 'success', 'Desktop', '2025-11-26 15:08:55'),
(87, 3, 'gian123', 'success', 'Desktop', '2025-11-26 15:18:43'),
(88, 4, 'camile123', 'success', 'Desktop', '2025-11-26 16:27:42'),
(89, 4, 'camile123', 'success', 'Mobile', '2025-11-26 18:16:47'),
(90, 3, 'gian123', 'success', 'Desktop', '2025-11-26 23:27:10'),
(91, 3, 'gian123', 'success', 'Desktop', '2025-11-26 23:27:33'),
(92, 3, 'gian123', 'success', 'Desktop', '2025-11-27 00:21:13'),
(93, NULL, 'sander.graaf@mailrez.com', 'failure', 'Desktop', '2025-11-27 05:41:43'),
(94, NULL, 'lars79', 'failure', 'Desktop', '2025-11-27 05:41:49'),
(95, NULL, 'lars79', 'failure', 'Desktop', '2025-11-27 05:41:54'),
(96, NULL, 'sander.graaf@mailrez.com', 'failure', 'Desktop', '2025-11-27 05:42:00'),
(97, NULL, 'sander.graaf@mailrez.com', 'failure', 'Desktop', '2025-11-27 05:42:15'),
(98, 3, 'gian123', 'success', 'Desktop', '2025-11-27 09:09:24'),
(99, 3, 'gian123', 'success', 'Desktop', '2025-11-27 10:48:32'),
(100, 4, 'camile123', 'success', 'Desktop', '2025-11-27 11:36:44'),
(101, NULL, 'klain123', 'success', 'Desktop', '2025-11-27 11:40:22'),
(102, 3, 'Gian123', 'success', 'Mobile', '2025-11-28 08:10:40'),
(103, 3, 'gian123', 'success', 'Desktop', '2025-11-28 22:36:11'),
(104, 4, 'camile123', 'success', 'Desktop', '2025-11-29 20:44:46'),
(105, 3, 'gian123', 'success', 'Desktop', '2025-11-29 21:05:09'),
(106, 4, 'camile123', 'success', 'Desktop', '2025-11-29 21:15:45'),
(107, 3, 'gian123', 'failure', 'Desktop', '2025-11-29 21:34:25'),
(108, 3, 'gian123', 'success', 'Desktop', '2025-11-29 21:34:50'),
(109, 3, 'gian123', 'success', 'Desktop', '2025-11-30 10:57:37'),
(110, 3, 'gian123', 'success', 'Desktop', '2025-11-30 14:54:42'),
(111, 3, 'gian123', 'success', 'Desktop', '2025-11-30 15:55:24'),
(112, NULL, 'barton.kling@moneysquad.org', 'failure', 'Mobile', '2025-12-01 02:51:35'),
(113, NULL, 'brooks.turcotte', 'failure', 'Mobile', '2025-12-01 02:51:42'),
(114, NULL, 'brooks.turcotte', 'failure', 'Mobile', '2025-12-01 02:51:50'),
(115, NULL, 'barton.kling@moneysquad.org', 'failure', 'Mobile', '2025-12-01 02:51:57'),
(116, NULL, 'barton.kling@moneysquad.org', 'failure', 'Mobile', '2025-12-01 02:52:15'),
(117, 3, 'gian123', 'success', 'Desktop', '2025-12-01 09:32:24'),
(118, 3, 'gian123', 'success', 'Desktop', '2025-12-01 15:53:39'),
(119, NULL, 'klain123', 'success', 'Desktop', '2025-12-01 15:56:28'),
(120, 3, 'gian123', 'success', 'Desktop', '2025-12-01 21:11:16'),
(121, 3, 'gian123', 'success', 'Desktop', '2025-12-01 22:25:35'),
(122, 3, 'gian123', 'success', 'Mobile', '2025-12-02 15:31:36'),
(123, 3, 'gian123', 'success', 'Desktop', '2025-12-02 17:04:36'),
(124, 3, 'gian123', 'success', 'Desktop', '2025-12-02 20:44:12'),
(125, 3, 'gian123', 'success', 'Desktop', '2025-12-03 08:50:54'),
(126, 3, 'gian123', 'failure', 'Desktop', '2025-12-03 09:57:52'),
(127, 3, 'gian123', 'success', 'Desktop', '2025-12-03 09:58:02'),
(128, 3, 'gian123', 'success', 'Desktop', '2025-12-03 10:18:26'),
(129, 3, 'gian123', 'success', 'Desktop', '2025-12-03 10:19:54'),
(130, 4, 'camile123', 'failure', 'Desktop', '2025-12-03 10:20:43'),
(131, 4, 'camile123', 'failure', 'Desktop', '2025-12-03 10:21:03'),
(132, 3, 'gian123', 'success', 'Desktop', '2025-12-03 10:21:07'),
(133, 4, 'camile123', 'success', 'Desktop', '2025-12-03 10:21:42'),
(134, NULL, 'klain123', 'failure', 'Desktop', '2025-12-03 10:22:28'),
(135, NULL, 'klain123', 'success', 'Desktop', '2025-12-03 10:22:51'),
(136, 3, 'gian123', 'failure', 'Desktop', '2025-12-03 21:45:34'),
(137, 3, 'gian123', 'success', 'Desktop', '2025-12-03 21:45:42'),
(138, NULL, 'klain123', 'failure', 'Desktop', '2025-12-03 22:16:03'),
(139, NULL, 'klain123', 'success', 'Desktop', '2025-12-03 22:16:11'),
(140, 3, 'gian123', 'success', 'Desktop', '2025-12-03 22:16:55'),
(141, 3, 'gian123', 'success', 'Desktop', '2025-12-04 10:23:27'),
(142, 3, 'gian123', 'success', 'Desktop', '2025-12-04 12:57:50'),
(143, 3, 'gian123', 'success', 'Mobile', '2025-12-05 06:10:58'),
(144, 3, 'gian123', 'success', 'Desktop', '2025-12-05 07:31:06'),
(145, NULL, 'klain123', 'failure', 'Mobile', '2025-12-05 08:36:52'),
(146, NULL, 'klain123', 'success', 'Mobile', '2025-12-05 08:37:03'),
(147, 3, 'gian123', 'success', 'Desktop', '2025-12-05 09:31:50'),
(148, 3, 'gian123', 'success', 'Desktop', '2025-12-05 11:04:14'),
(149, 3, 'gian123', 'success', 'Mobile', '2025-12-05 13:48:56'),
(150, 3, 'gian123', 'success', 'Desktop', '2025-12-05 13:58:52'),
(151, 3, 'gian123', 'success', 'Desktop', '2025-12-05 13:59:42'),
(152, 3, 'gian123', 'success', 'Mobile', '2025-12-05 14:05:47'),
(153, 3, 'gian123', 'success', 'Mobile', '2025-12-05 14:09:38'),
(154, 3, 'gian123', 'success', 'Mobile', '2025-12-05 15:01:18'),
(155, 3, 'gian123', 'success', 'Desktop', '2025-12-05 15:01:24'),
(156, 8, 'Dreed123', 'success', 'Mobile', '2025-12-05 15:10:41'),
(157, 9, 'janjan0618', 'success', 'Desktop', '2025-12-05 15:12:06'),
(158, 9, 'janjan0618', 'success', 'Mobile', '2025-12-05 15:12:24'),
(159, 3, 'gian123', 'success', 'Desktop', '2025-12-05 15:13:07'),
(160, 3, 'gian123', 'success', 'Desktop', '2025-12-05 15:19:12'),
(161, 8, 'Dreed123', 'success', 'Mobile', '2025-12-05 15:26:04'),
(162, 9, 'janjan0618', 'success', 'Mobile', '2025-12-05 15:37:01'),
(163, 3, 'gian123', 'success', 'Desktop', '2025-12-05 16:04:34'),
(164, 3, 'gian123', 'success', 'Desktop', '2025-12-05 16:17:55'),
(165, 3, 'gian123', 'success', 'Desktop', '2025-12-05 22:14:10'),
(166, 3, 'gian123', 'success', 'Mobile', '2025-12-06 00:30:59'),
(167, 3, 'gian123', 'success', 'Mobile', '2025-12-06 08:50:21'),
(168, 3, 'gian123', 'success', 'Mobile', '2025-12-06 09:17:37'),
(169, 8, 'Dreed123', 'success', 'Mobile', '2025-12-07 06:50:02'),
(170, 3, 'gian123', 'success', 'Desktop', '2025-12-07 14:44:39'),
(171, 3, 'gian123', 'success', 'Desktop', '2025-12-07 14:58:21'),
(172, 3, 'gian123', 'success', 'Desktop', '2025-12-07 15:48:43'),
(173, NULL, 'klain', 'failure', 'Desktop', '2025-12-07 17:25:07'),
(174, NULL, 'klain123', 'failure', 'Desktop', '2025-12-07 17:25:17'),
(175, NULL, 'klain123', 'failure', 'Desktop', '2025-12-07 17:25:23'),
(176, NULL, 'klain123', 'failure', 'Desktop', '2025-12-07 17:25:37'),
(177, NULL, 'klain123', 'failure', 'Desktop', '2025-12-07 17:26:04'),
(178, NULL, 'klain123', 'failure', 'Desktop', '2025-12-07 17:26:04'),
(179, 3, 'gian123', 'success', 'Desktop', '2025-12-07 17:27:15'),
(180, NULL, 'klain123', 'failure', 'Desktop', '2025-12-07 18:50:40'),
(181, 3, 'gian123', 'success', 'Desktop', '2025-12-07 19:00:05'),
(182, 3, 'gian123', 'success', 'Desktop', '2025-12-08 09:04:40'),
(183, 3, 'gian123', 'failure', 'Desktop', '2025-12-08 10:24:34'),
(184, 3, 'gian123', 'success', 'Desktop', '2025-12-08 10:24:41'),
(185, 3, 'gian123', 'success', 'Mobile', '2025-12-08 11:49:39'),
(186, 13, 'managertrial', 'success', 'Desktop', '2025-12-08 11:55:22'),
(187, 3, 'gian123', 'success', 'Desktop', '2025-12-08 15:23:55'),
(188, 3, 'gian123', 'success', 'Desktop', '2025-12-08 20:53:04');

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
(1, NULL, 20.00, '2025-10-26 06:05:00'),
(2, NULL, 18.00, '2025-10-26 06:35:00'),
(3, NULL, 50.00, '2025-10-26 07:05:00'),
(4, NULL, 30.00, '2025-10-26 07:35:00'),
(5, NULL, 40.00, '2025-10-26 08:05:00'),
(6, NULL, 36.00, '2025-10-26 08:35:00'),
(7, NULL, 60.00, '2025-10-26 09:05:00'),
(8, NULL, 75.00, '2025-10-26 09:35:00'),
(9, NULL, 75.00, '2025-10-26 10:05:00'),
(10, NULL, 60.00, '2025-10-26 10:35:00'),
(11, NULL, 60.00, '2025-10-26 11:05:00'),
(12, NULL, 60.00, '2025-10-26 11:35:00'),
(13, NULL, 90.00, '2025-10-26 12:05:00'),
(14, NULL, 20.00, '2025-10-26 12:35:00'),
(15, NULL, 54.00, '2025-10-27 06:25:00'),
(16, NULL, 60.00, '2025-10-27 06:55:00'),
(17, NULL, 75.00, '2025-10-27 07:25:00'),
(18, NULL, 80.00, '2025-10-27 07:55:00'),
(19, NULL, 60.00, '2025-10-27 08:25:00'),
(20, NULL, 80.00, '2025-10-27 08:55:00'),
(21, NULL, 60.00, '2025-10-27 09:25:00'),
(22, NULL, 50.00, '2025-10-27 09:55:00'),
(23, NULL, 75.00, '2025-10-27 10:25:00'),
(24, NULL, 60.00, '2025-10-27 10:55:00'),
(25, NULL, 48.00, '2025-10-27 11:25:00'),
(26, NULL, 30.00, '2025-10-27 11:55:00'),
(27, NULL, 30.00, '2025-10-27 12:25:00'),
(28, NULL, 36.00, '2025-10-28 06:15:00'),
(29, NULL, 100.00, '2025-10-28 06:45:00'),
(30, NULL, 15.00, '2025-10-28 07:15:00'),
(31, NULL, 40.00, '2025-10-28 07:45:00'),
(32, NULL, 12.00, '2025-10-28 08:15:00'),
(33, NULL, 40.00, '2025-10-28 08:45:00'),
(34, NULL, 60.00, '2025-10-28 09:15:00'),
(35, NULL, 50.00, '2025-10-28 09:45:00'),
(36, NULL, 45.00, '2025-10-28 10:15:00'),
(37, NULL, 90.00, '2025-10-28 10:45:00'),
(38, NULL, 24.00, '2025-10-28 11:15:00'),
(39, NULL, 60.00, '2025-10-28 11:45:00'),
(40, NULL, 24.00, '2025-10-28 12:15:00'),
(41, NULL, 72.00, '2025-10-29 06:25:00'),
(42, NULL, 50.00, '2025-10-29 06:55:00'),
(43, NULL, 15.00, '2025-10-29 07:25:00'),
(44, NULL, 40.00, '2025-10-29 07:55:00'),
(45, NULL, 60.00, '2025-10-29 08:25:00'),
(46, NULL, 60.00, '2025-10-29 08:55:00'),
(47, NULL, 75.00, '2025-10-29 09:25:00'),
(48, NULL, 75.00, '2025-10-29 09:55:00'),
(49, NULL, 30.00, '2025-10-29 10:25:00'),
(50, NULL, 60.00, '2025-10-29 10:55:00'),
(51, NULL, 60.00, '2025-10-29 11:25:00'),
(52, NULL, 90.00, '2025-10-29 11:55:00'),
(53, NULL, 20.00, '2025-10-29 12:35:00'),
(54, NULL, 18.00, '2025-10-30 06:15:00'),
(55, NULL, 80.00, '2025-10-30 06:45:00'),
(56, NULL, 75.00, '2025-10-30 07:15:00'),
(57, NULL, 80.00, '2025-10-30 07:45:00'),
(58, NULL, 12.00, '2025-10-30 08:15:00'),
(59, NULL, 100.00, '2025-10-30 08:45:00'),
(60, NULL, 60.00, '2025-10-30 09:15:00'),
(61, NULL, 25.00, '2025-10-30 09:45:00'),
(62, NULL, 60.00, '2025-10-30 10:15:00'),
(63, NULL, 45.00, '2025-10-30 10:45:00'),
(64, NULL, 36.00, '2025-10-30 11:15:00'),
(65, NULL, 90.00, '2025-10-30 11:45:00'),
(66, NULL, 30.00, '2025-10-30 12:15:00'),
(67, NULL, 72.00, '2025-10-31 06:25:00'),
(68, NULL, 50.00, '2025-10-31 06:55:00'),
(69, NULL, 60.00, '2025-10-31 07:25:00'),
(70, NULL, 80.00, '2025-10-31 07:55:00'),
(71, NULL, 60.00, '2025-10-31 08:25:00'),
(72, NULL, 100.00, '2025-10-31 08:55:00'),
(73, NULL, 75.00, '2025-10-31 09:25:00'),
(74, NULL, 75.00, '2025-10-31 09:55:00'),
(75, NULL, 30.00, '2025-10-31 10:25:00'),
(76, NULL, 75.00, '2025-10-31 10:55:00'),
(77, NULL, 36.00, '2025-10-31 11:25:00'),
(78, NULL, 30.00, '2025-10-31 11:55:00'),
(79, NULL, 20.00, '2025-11-01 06:05:00'),
(80, NULL, 90.00, '2025-11-01 06:35:00'),
(81, NULL, 100.00, '2025-11-01 07:05:00'),
(82, NULL, 75.00, '2025-11-01 07:35:00'),
(83, NULL, 40.00, '2025-11-01 08:05:00'),
(84, NULL, 36.00, '2025-11-01 08:35:00'),
(85, NULL, 80.00, '2025-11-01 09:05:00'),
(86, NULL, 90.00, '2025-11-01 09:35:00'),
(87, NULL, 75.00, '2025-11-01 10:05:00'),
(88, NULL, 60.00, '2025-11-01 10:35:00'),
(89, NULL, 60.00, '2025-11-01 11:05:00'),
(90, NULL, 36.00, '2025-11-01 11:35:00'),
(91, NULL, 30.00, '2025-11-01 12:05:00'),
(92, NULL, 30.00, '2025-11-02 06:05:00'),
(93, NULL, 54.00, '2025-11-02 06:35:00'),
(94, NULL, 100.00, '2025-11-02 07:05:00'),
(95, NULL, 45.00, '2025-11-02 07:35:00'),
(96, NULL, 80.00, '2025-11-02 08:05:00'),
(97, NULL, 48.00, '2025-11-02 08:35:00'),
(98, NULL, 60.00, '2025-11-02 09:05:00'),
(99, NULL, 90.00, '2025-11-02 09:35:00'),
(100, NULL, 50.00, '2025-11-02 10:05:00'),
(101, NULL, 45.00, '2025-11-02 10:35:00'),
(102, NULL, 75.00, '2025-11-02 11:05:00'),
(103, NULL, 48.00, '2025-11-02 11:35:00'),
(104, NULL, 90.00, '2025-11-02 12:05:00'),
(105, NULL, 20.00, '2025-11-02 12:35:00'),
(106, NULL, 18.00, '2025-11-03 06:25:00'),
(107, NULL, 100.00, '2025-11-03 06:55:00'),
(108, NULL, 15.00, '2025-11-03 07:25:00'),
(109, NULL, 40.00, '2025-11-03 07:55:00'),
(110, NULL, 12.00, '2025-11-03 08:25:00'),
(111, NULL, 40.00, '2025-11-03 08:55:00'),
(112, NULL, 60.00, '2025-11-03 09:25:00'),
(113, NULL, 50.00, '2025-11-03 09:55:00'),
(114, NULL, 45.00, '2025-11-03 10:25:00'),
(115, NULL, 90.00, '2025-11-03 10:55:00'),
(116, NULL, 24.00, '2025-11-03 11:25:00'),
(117, NULL, 60.00, '2025-11-03 11:55:00'),
(118, NULL, 20.00, '2025-11-04 06:05:00'),
(119, NULL, 72.00, '2025-11-04 06:35:00'),
(120, NULL, 50.00, '2025-11-04 07:05:00'),
(121, NULL, 60.00, '2025-11-04 07:35:00'),
(122, NULL, 80.00, '2025-11-04 08:05:00'),
(123, NULL, 60.00, '2025-11-04 08:35:00'),
(124, NULL, 100.00, '2025-11-04 09:05:00'),
(125, NULL, 75.00, '2025-11-04 09:35:00'),
(126, NULL, 75.00, '2025-11-04 10:05:00'),
(127, NULL, 30.00, '2025-11-04 10:35:00'),
(128, NULL, 75.00, '2025-11-04 11:05:00'),
(129, NULL, 36.00, '2025-11-04 11:35:00'),
(130, NULL, 30.00, '2025-11-04 12:05:00'),
(131, NULL, 20.00, '2025-11-04 12:35:00'),
(132, NULL, 18.00, '2025-11-05 06:25:00'),
(133, NULL, 80.00, '2025-11-05 06:55:00'),
(134, NULL, 75.00, '2025-11-05 07:25:00'),
(135, NULL, 80.00, '2025-11-05 07:55:00'),
(136, NULL, 12.00, '2025-11-05 08:25:00'),
(137, NULL, 100.00, '2025-11-05 08:55:00'),
(138, NULL, 60.00, '2025-11-05 09:25:00'),
(139, NULL, 25.00, '2025-11-05 09:55:00'),
(140, NULL, 60.00, '2025-11-05 10:25:00'),
(141, NULL, 45.00, '2025-11-05 10:55:00'),
(142, NULL, 36.00, '2025-11-05 11:25:00'),
(143, NULL, 90.00, '2025-11-05 11:55:00'),
(144, NULL, 20.00, '2025-11-06 06:05:00'),
(145, NULL, 72.00, '2025-11-06 06:35:00'),
(146, NULL, 50.00, '2025-11-06 07:05:00'),
(147, NULL, 15.00, '2025-11-06 07:35:00'),
(148, NULL, 40.00, '2025-11-06 08:05:00'),
(149, NULL, 60.00, '2025-11-06 08:35:00'),
(150, NULL, 60.00, '2025-11-06 09:05:00'),
(151, NULL, 75.00, '2025-11-06 09:35:00'),
(152, NULL, 75.00, '2025-11-06 10:05:00'),
(153, NULL, 30.00, '2025-11-06 10:35:00'),
(154, NULL, 60.00, '2025-11-06 11:05:00'),
(155, NULL, 60.00, '2025-11-06 11:35:00'),
(156, NULL, 90.00, '2025-11-06 12:05:00'),
(157, NULL, 20.00, '2025-11-06 12:35:00'),
(158, NULL, 72.00, '2025-11-07 06:25:00'),
(159, NULL, 50.00, '2025-11-07 06:55:00'),
(160, NULL, 60.00, '2025-11-07 07:25:00'),
(161, NULL, 80.00, '2025-11-07 07:55:00'),
(162, NULL, 60.00, '2025-11-07 08:25:00'),
(163, NULL, 100.00, '2025-11-07 08:55:00'),
(164, NULL, 75.00, '2025-11-07 09:25:00'),
(165, NULL, 75.00, '2025-11-07 09:55:00'),
(166, NULL, 30.00, '2025-11-07 10:25:00'),
(167, NULL, 75.00, '2025-11-07 10:55:00'),
(168, NULL, 36.00, '2025-11-07 11:25:00'),
(169, NULL, 30.00, '2025-11-07 11:55:00'),
(170, NULL, 20.00, '2025-11-08 06:05:00'),
(171, NULL, 90.00, '2025-11-08 06:35:00'),
(172, NULL, 100.00, '2025-11-08 07:05:00'),
(173, NULL, 75.00, '2025-11-08 07:35:00'),
(174, NULL, 40.00, '2025-11-08 08:05:00'),
(175, NULL, 36.00, '2025-11-08 08:35:00'),
(176, NULL, 80.00, '2025-11-08 09:05:00'),
(177, NULL, 90.00, '2025-11-08 09:35:00'),
(178, NULL, 75.00, '2025-11-08 10:05:00'),
(179, NULL, 60.00, '2025-11-08 10:35:00'),
(180, NULL, 60.00, '2025-11-08 11:05:00'),
(181, NULL, 36.00, '2025-11-08 11:35:00'),
(182, NULL, 30.00, '2025-11-08 12:05:00'),
(183, NULL, 30.00, '2025-11-09 06:05:00'),
(184, NULL, 54.00, '2025-11-09 06:35:00'),
(185, NULL, 100.00, '2025-11-09 07:05:00'),
(186, NULL, 45.00, '2025-11-09 07:35:00'),
(187, NULL, 80.00, '2025-11-09 08:05:00'),
(188, NULL, 48.00, '2025-11-09 08:35:00'),
(189, NULL, 60.00, '2025-11-09 09:05:00'),
(190, NULL, 90.00, '2025-11-09 09:35:00'),
(191, NULL, 50.00, '2025-11-09 10:05:00'),
(192, NULL, 45.00, '2025-11-09 10:35:00'),
(193, NULL, 75.00, '2025-11-09 11:05:00'),
(194, NULL, 48.00, '2025-11-09 11:35:00'),
(195, NULL, 90.00, '2025-11-09 12:05:00'),
(196, NULL, 20.00, '2025-11-09 12:35:00'),
(197, NULL, 18.00, '2025-11-10 06:25:00'),
(198, NULL, 100.00, '2025-11-10 06:55:00'),
(199, NULL, 15.00, '2025-11-10 07:25:00'),
(200, NULL, 40.00, '2025-11-10 07:55:00'),
(201, NULL, 12.00, '2025-11-10 08:25:00'),
(202, NULL, 40.00, '2025-11-10 08:55:00'),
(203, NULL, 60.00, '2025-11-10 09:25:00'),
(204, NULL, 50.00, '2025-11-10 09:55:00'),
(205, NULL, 45.00, '2025-11-10 10:25:00'),
(206, NULL, 90.00, '2025-11-10 10:55:00'),
(207, NULL, 24.00, '2025-11-10 11:25:00'),
(208, NULL, 60.00, '2025-11-10 11:55:00'),
(209, NULL, 20.00, '2025-11-11 06:05:00'),
(210, NULL, 72.00, '2025-11-11 06:35:00'),
(211, NULL, 50.00, '2025-11-11 07:05:00'),
(212, NULL, 15.00, '2025-11-11 07:35:00'),
(213, NULL, 40.00, '2025-11-11 08:05:00'),
(214, NULL, 60.00, '2025-11-11 08:35:00'),
(215, NULL, 60.00, '2025-11-11 09:05:00'),
(216, NULL, 75.00, '2025-11-11 09:35:00'),
(217, NULL, 75.00, '2025-11-11 10:05:00'),
(218, NULL, 30.00, '2025-11-11 10:35:00'),
(219, NULL, 60.00, '2025-11-11 11:05:00'),
(220, NULL, 60.00, '2025-11-11 11:35:00'),
(221, NULL, 90.00, '2025-11-11 12:05:00'),
(222, NULL, 20.00, '2025-11-11 12:35:00'),
(223, NULL, 18.00, '2025-11-12 06:25:00'),
(224, NULL, 80.00, '2025-11-12 06:55:00'),
(225, NULL, 75.00, '2025-11-12 07:25:00'),
(226, NULL, 80.00, '2025-11-12 07:55:00'),
(227, NULL, 12.00, '2025-11-12 08:25:00'),
(228, NULL, 100.00, '2025-11-12 08:55:00'),
(229, NULL, 60.00, '2025-11-12 09:25:00'),
(230, NULL, 25.00, '2025-11-12 09:55:00'),
(231, NULL, 60.00, '2025-11-12 10:25:00'),
(232, NULL, 45.00, '2025-11-12 10:55:00'),
(233, NULL, 36.00, '2025-11-12 11:25:00'),
(234, NULL, 90.00, '2025-11-12 11:55:00'),
(235, NULL, 20.00, '2025-11-13 06:05:00'),
(236, NULL, 72.00, '2025-11-13 06:35:00'),
(237, NULL, 50.00, '2025-11-13 07:05:00'),
(238, NULL, 15.00, '2025-11-13 07:35:00'),
(239, NULL, 40.00, '2025-11-13 08:05:00'),
(240, NULL, 60.00, '2025-11-13 08:35:00'),
(241, NULL, 60.00, '2025-11-13 09:05:00'),
(242, NULL, 75.00, '2025-11-13 09:35:00'),
(243, NULL, 75.00, '2025-11-13 10:05:00'),
(244, NULL, 30.00, '2025-11-13 10:35:00'),
(245, NULL, 60.00, '2025-11-13 11:05:00'),
(246, NULL, 60.00, '2025-11-13 11:35:00'),
(247, NULL, 90.00, '2025-11-13 12:05:00'),
(248, NULL, 20.00, '2025-11-13 12:35:00'),
(249, NULL, 72.00, '2025-11-14 06:25:00'),
(250, NULL, 50.00, '2025-11-14 06:55:00'),
(251, NULL, 60.00, '2025-11-14 07:25:00'),
(252, NULL, 80.00, '2025-11-14 07:55:00'),
(253, NULL, 60.00, '2025-11-14 08:25:00'),
(254, NULL, 100.00, '2025-11-14 08:55:00'),
(255, NULL, 75.00, '2025-11-14 09:25:00'),
(256, NULL, 75.00, '2025-11-14 09:55:00'),
(257, NULL, 30.00, '2025-11-14 10:25:00'),
(258, NULL, 75.00, '2025-11-14 10:55:00'),
(259, NULL, 36.00, '2025-11-14 11:25:00'),
(260, NULL, 30.00, '2025-11-14 11:55:00'),
(261, NULL, 20.00, '2025-11-15 06:05:00'),
(262, NULL, 90.00, '2025-11-15 06:35:00'),
(263, NULL, 100.00, '2025-11-15 07:05:00'),
(264, NULL, 75.00, '2025-11-15 07:35:00'),
(265, NULL, 40.00, '2025-11-15 08:05:00'),
(266, NULL, 36.00, '2025-11-15 08:35:00'),
(267, NULL, 80.00, '2025-11-15 09:05:00'),
(268, NULL, 90.00, '2025-11-15 09:35:00'),
(269, NULL, 75.00, '2025-11-15 10:05:00'),
(270, NULL, 60.00, '2025-11-15 10:35:00'),
(271, NULL, 60.00, '2025-11-15 11:05:00'),
(272, NULL, 36.00, '2025-11-15 11:35:00'),
(273, NULL, 30.00, '2025-11-15 12:05:00'),
(274, NULL, 30.00, '2025-11-16 06:05:00'),
(275, NULL, 54.00, '2025-11-16 06:35:00'),
(276, NULL, 100.00, '2025-11-16 07:05:00'),
(277, NULL, 45.00, '2025-11-16 07:35:00'),
(278, NULL, 80.00, '2025-11-16 08:05:00'),
(279, NULL, 48.00, '2025-11-16 08:35:00'),
(280, NULL, 60.00, '2025-11-16 09:05:00'),
(281, NULL, 90.00, '2025-11-16 09:35:00'),
(282, NULL, 50.00, '2025-11-16 10:05:00'),
(283, NULL, 45.00, '2025-11-16 10:35:00'),
(284, NULL, 75.00, '2025-11-16 11:05:00'),
(285, NULL, 48.00, '2025-11-16 11:35:00'),
(286, NULL, 90.00, '2025-11-16 12:05:00'),
(287, NULL, 20.00, '2025-11-16 12:35:00'),
(288, NULL, 18.00, '2025-11-17 06:25:00'),
(289, NULL, 100.00, '2025-11-17 06:55:00'),
(290, NULL, 15.00, '2025-11-17 07:25:00'),
(291, NULL, 40.00, '2025-11-17 07:55:00'),
(292, NULL, 12.00, '2025-11-17 08:25:00'),
(293, NULL, 40.00, '2025-11-17 08:55:00'),
(294, NULL, 60.00, '2025-11-17 09:25:00'),
(295, NULL, 50.00, '2025-11-17 09:55:00'),
(296, NULL, 45.00, '2025-11-17 10:25:00'),
(297, NULL, 90.00, '2025-11-17 10:55:00'),
(298, NULL, 24.00, '2025-11-17 11:25:00'),
(299, NULL, 60.00, '2025-11-17 11:55:00'),
(300, NULL, 20.00, '2025-11-18 06:05:00'),
(301, NULL, 72.00, '2025-11-18 06:35:00'),
(302, NULL, 50.00, '2025-11-18 07:05:00'),
(303, NULL, 15.00, '2025-11-18 07:35:00'),
(304, NULL, 40.00, '2025-11-18 08:05:00'),
(305, NULL, 60.00, '2025-11-18 08:35:00'),
(306, NULL, 60.00, '2025-11-18 09:05:00'),
(307, NULL, 75.00, '2025-11-18 09:35:00'),
(308, NULL, 75.00, '2025-11-18 10:05:00'),
(309, NULL, 30.00, '2025-11-18 10:35:00'),
(310, NULL, 60.00, '2025-11-18 11:05:00'),
(311, NULL, 60.00, '2025-11-18 11:35:00'),
(312, NULL, 90.00, '2025-11-18 12:05:00'),
(313, NULL, 20.00, '2025-11-18 12:35:00'),
(314, NULL, 18.00, '2025-11-19 06:25:00'),
(315, NULL, 80.00, '2025-11-19 06:55:00'),
(316, NULL, 75.00, '2025-11-19 07:25:00'),
(317, NULL, 80.00, '2025-11-19 07:55:00'),
(318, NULL, 12.00, '2025-11-19 08:25:00'),
(319, NULL, 100.00, '2025-11-19 08:55:00'),
(320, NULL, 60.00, '2025-11-19 09:25:00'),
(321, NULL, 25.00, '2025-11-19 09:55:00'),
(322, NULL, 60.00, '2025-11-19 10:25:00'),
(323, NULL, 45.00, '2025-11-19 10:55:00'),
(324, NULL, 36.00, '2025-11-19 11:25:00'),
(325, NULL, 90.00, '2025-11-19 11:55:00'),
(326, NULL, 20.00, '2025-11-20 06:05:00'),
(327, NULL, 72.00, '2025-11-20 06:35:00'),
(328, NULL, 50.00, '2025-11-20 07:05:00'),
(329, NULL, 15.00, '2025-11-20 07:35:00'),
(330, NULL, 40.00, '2025-11-20 08:05:00'),
(331, NULL, 60.00, '2025-11-20 08:35:00'),
(332, NULL, 60.00, '2025-11-20 09:05:00'),
(333, NULL, 75.00, '2025-11-20 09:35:00'),
(334, NULL, 75.00, '2025-11-20 10:05:00'),
(335, NULL, 30.00, '2025-11-20 10:35:00'),
(336, NULL, 60.00, '2025-11-20 11:05:00'),
(337, NULL, 60.00, '2025-11-20 11:35:00'),
(338, NULL, 90.00, '2025-11-20 12:05:00'),
(339, NULL, 20.00, '2025-11-20 12:35:00'),
(340, NULL, 72.00, '2025-11-21 06:25:00'),
(341, NULL, 50.00, '2025-11-21 06:55:00'),
(342, NULL, 60.00, '2025-11-21 07:25:00'),
(343, NULL, 80.00, '2025-11-21 07:55:00'),
(344, NULL, 60.00, '2025-11-21 08:25:00'),
(345, NULL, 100.00, '2025-11-21 08:55:00'),
(346, NULL, 75.00, '2025-11-21 09:25:00'),
(347, NULL, 75.00, '2025-11-21 09:55:00'),
(348, NULL, 30.00, '2025-11-21 10:25:00'),
(349, NULL, 75.00, '2025-11-21 10:55:00'),
(350, NULL, 36.00, '2025-11-21 11:25:00'),
(351, NULL, 30.00, '2025-11-21 11:55:00'),
(352, NULL, 20.00, '2025-11-22 06:05:00'),
(353, NULL, 90.00, '2025-11-22 06:35:00'),
(354, NULL, 100.00, '2025-11-22 07:05:00'),
(355, NULL, 75.00, '2025-11-22 07:35:00'),
(356, NULL, 40.00, '2025-11-22 08:05:00'),
(357, NULL, 36.00, '2025-11-22 08:35:00'),
(358, NULL, 80.00, '2025-11-22 09:05:00'),
(359, NULL, 90.00, '2025-11-22 09:35:00'),
(360, NULL, 75.00, '2025-11-22 10:05:00'),
(361, NULL, 60.00, '2025-11-22 10:35:00'),
(362, NULL, 60.00, '2025-11-22 11:05:00'),
(363, NULL, 36.00, '2025-11-22 11:35:00'),
(364, NULL, 30.00, '2025-11-22 12:05:00'),
(365, NULL, 20.00, '2025-11-22 12:35:00'),
(366, NULL, 54.00, '2025-11-23 06:25:00'),
(367, NULL, 100.00, '2025-11-23 06:55:00'),
(368, NULL, 45.00, '2025-11-23 07:25:00'),
(369, NULL, 80.00, '2025-11-23 07:55:00'),
(370, NULL, 48.00, '2025-11-23 08:35:00'),
(371, NULL, 60.00, '2025-11-23 09:05:00'),
(372, NULL, 90.00, '2025-11-23 09:35:00'),
(373, NULL, 50.00, '2025-11-23 10:05:00'),
(374, NULL, 45.00, '2025-11-23 10:35:00'),
(375, NULL, 75.00, '2025-11-23 11:05:00'),
(376, NULL, 48.00, '2025-11-23 11:35:00'),
(377, NULL, 90.00, '2025-11-23 12:05:00'),
(378, NULL, 20.00, '2025-11-23 12:35:00'),
(379, NULL, 18.00, '2025-11-24 06:25:00'),
(380, NULL, 100.00, '2025-11-24 06:55:00'),
(381, NULL, 15.00, '2025-11-24 07:25:00'),
(382, NULL, 40.00, '2025-11-24 07:55:00'),
(383, NULL, 12.00, '2025-11-24 08:25:00'),
(384, NULL, 40.00, '2025-11-24 08:55:00'),
(385, NULL, 60.00, '2025-11-24 09:25:00'),
(386, NULL, 50.00, '2025-11-24 09:55:00'),
(387, NULL, 45.00, '2025-11-24 10:25:00'),
(388, NULL, 90.00, '2025-11-24 10:55:00'),
(389, NULL, 24.00, '2025-11-24 11:25:00'),
(390, NULL, 60.00, '2025-11-24 11:55:00'),
(391, NULL, 20.00, '2025-11-25 06:05:00'),
(392, NULL, 72.00, '2025-11-25 06:35:00'),
(393, NULL, 50.00, '2025-11-25 07:05:00'),
(394, NULL, 15.00, '2025-11-25 07:35:00'),
(395, NULL, 40.00, '2025-11-25 08:05:00'),
(396, NULL, 60.00, '2025-11-25 08:35:00'),
(397, NULL, 60.00, '2025-11-25 09:05:00'),
(398, NULL, 75.00, '2025-11-25 09:35:00'),
(399, NULL, 75.00, '2025-11-25 10:05:00'),
(400, NULL, 30.00, '2025-11-25 10:35:00'),
(401, NULL, 60.00, '2025-11-25 11:05:00'),
(402, NULL, 60.00, '2025-11-25 11:35:00'),
(403, NULL, 90.00, '2025-11-25 12:05:00'),
(404, NULL, 20.00, '2025-11-25 12:35:00'),
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
(2050, 3, 126.90, '2025-11-25 20:23:46'),
(2051, NULL, 394.00, '2025-10-27 10:13:07'),
(2052, 5, 353.00, '2025-10-27 15:53:30'),
(2053, 5, 256.00, '2025-10-27 16:14:26'),
(2054, 6, 449.00, '2025-10-27 11:37:17'),
(2055, 5, 198.00, '2025-10-27 08:15:41'),
(2056, 5, 368.00, '2025-10-27 10:02:28'),
(2057, NULL, 44.00, '2025-10-27 14:09:49'),
(2058, NULL, 230.00, '2025-10-27 18:38:19'),
(2059, 6, 300.00, '2025-10-27 13:22:22'),
(2060, 5, 441.00, '2025-10-27 06:10:55'),
(2061, 6, 301.00, '2025-10-27 11:58:27'),
(2062, 6, 84.00, '2025-10-27 06:28:55'),
(2063, NULL, 325.00, '2025-10-27 11:36:44'),
(2064, 5, 88.00, '2025-10-28 14:05:40'),
(2065, 5, 307.00, '2025-10-28 08:54:13'),
(2066, NULL, 363.00, '2025-10-28 18:09:12'),
(2067, NULL, 340.00, '2025-10-28 18:31:45'),
(2068, 5, 377.00, '2025-10-28 12:48:23'),
(2069, 6, 291.00, '2025-10-28 14:43:21'),
(2070, 6, 164.00, '2025-10-28 09:27:55'),
(2071, 5, 260.00, '2025-10-28 10:13:51'),
(2072, 6, 129.00, '2025-10-28 13:45:00'),
(2073, 6, 150.00, '2025-10-28 07:41:56'),
(2074, NULL, 85.00, '2025-10-28 18:44:08'),
(2075, NULL, 315.00, '2025-10-28 09:03:03'),
(2076, 6, 248.00, '2025-10-28 11:36:40'),
(2077, 5, 75.00, '2025-10-28 11:28:15'),
(2078, 6, 383.00, '2025-10-28 12:50:22'),
(2079, NULL, 45.00, '2025-10-29 06:23:52'),
(2080, NULL, 60.00, '2025-10-29 16:43:33'),
(2081, 5, 201.00, '2025-10-29 18:25:36'),
(2082, 5, 253.00, '2025-10-29 08:43:24'),
(2083, 5, 174.00, '2025-10-29 08:25:50'),
(2084, NULL, 174.00, '2025-10-29 08:21:41'),
(2085, 5, 383.00, '2025-10-29 07:53:29'),
(2086, 5, 237.00, '2025-10-29 08:38:35'),
(2087, 6, 504.00, '2025-10-29 19:45:34'),
(2088, 6, 397.00, '2025-10-29 11:29:50'),
(2089, 6, 28.00, '2025-10-29 19:13:53'),
(2090, NULL, 324.00, '2025-10-29 06:45:38'),
(2091, NULL, 313.00, '2025-10-29 18:08:49'),
(2092, 5, 140.00, '2025-10-29 08:41:39'),
(2093, NULL, 419.00, '2025-10-29 13:50:10'),
(2094, NULL, 414.00, '2025-10-29 08:01:44'),
(2095, 6, 400.00, '2025-10-29 07:46:02'),
(2096, 5, 132.00, '2025-10-29 09:38:16'),
(2097, 6, 221.00, '2025-10-29 14:03:58'),
(2098, 5, 173.00, '2025-10-30 16:29:21'),
(2099, NULL, 185.00, '2025-10-30 10:21:54'),
(2100, 6, 360.00, '2025-10-30 18:48:50'),
(2101, NULL, 382.00, '2025-10-30 19:16:35'),
(2102, NULL, 368.00, '2025-10-30 07:41:02'),
(2103, NULL, 36.00, '2025-10-30 15:01:40'),
(2104, 6, 255.00, '2025-10-30 10:22:50'),
(2105, 5, 192.00, '2025-10-30 13:44:36'),
(2106, 5, 75.00, '2025-10-30 08:53:26'),
(2107, 6, 243.00, '2025-10-30 14:00:25'),
(2108, 6, 142.00, '2025-10-30 12:04:21'),
(2109, 5, 506.00, '2025-10-30 18:07:07');
INSERT INTO `orders` (`order_id`, `user_id`, `total_order_price`, `timestamp`) VALUES
(2110, 6, 454.00, '2025-10-30 07:32:21'),
(2111, 6, 262.00, '2025-10-30 08:51:39'),
(2112, 6, 148.00, '2025-10-30 19:32:09'),
(2113, 6, 171.00, '2025-10-30 17:35:40'),
(2114, 6, 170.00, '2025-10-30 12:05:33'),
(2115, 6, 361.00, '2025-10-31 13:20:35'),
(2116, 5, 382.00, '2025-10-31 12:31:52'),
(2117, NULL, 324.00, '2025-10-31 17:09:35'),
(2118, 6, 35.00, '2025-10-31 11:31:48'),
(2119, 6, 473.00, '2025-10-31 11:52:02'),
(2120, 5, 181.00, '2025-10-31 10:04:38'),
(2121, NULL, 242.00, '2025-10-31 07:53:14'),
(2122, NULL, 255.00, '2025-10-31 16:22:31'),
(2123, 5, 123.00, '2025-10-31 15:23:50'),
(2124, 5, 58.00, '2025-10-31 07:04:39'),
(2125, 5, 227.00, '2025-10-31 16:23:09'),
(2126, NULL, 389.00, '2025-10-31 17:48:32'),
(2127, 6, 105.00, '2025-10-31 18:26:03'),
(2128, 6, 217.00, '2025-10-31 10:42:34'),
(2129, 6, 45.00, '2025-11-01 09:39:46'),
(2130, 5, 375.00, '2025-11-01 19:59:22'),
(2131, 5, 444.00, '2025-11-01 17:16:34'),
(2132, 5, 189.00, '2025-11-01 19:42:22'),
(2133, NULL, 12.00, '2025-11-01 15:31:53'),
(2134, 5, 114.00, '2025-11-01 09:20:37'),
(2135, NULL, 417.00, '2025-11-01 07:41:01'),
(2136, NULL, 487.00, '2025-11-01 13:04:47'),
(2137, NULL, 45.00, '2025-11-01 17:03:04'),
(2138, 5, 151.00, '2025-11-01 08:46:36'),
(2139, 5, 38.00, '2025-11-01 15:04:58'),
(2140, 6, 314.00, '2025-11-01 16:12:44'),
(2141, 6, 285.00, '2025-11-01 09:45:20'),
(2142, 5, 89.00, '2025-11-01 11:43:38'),
(2143, NULL, 68.00, '2025-11-01 12:53:46'),
(2144, 5, 60.00, '2025-11-01 10:17:51'),
(2145, 5, 233.00, '2025-11-01 16:27:16'),
(2146, 6, 585.00, '2025-11-01 14:09:53'),
(2147, 6, 217.00, '2025-11-01 12:03:43'),
(2148, 6, 191.00, '2025-11-02 06:19:17'),
(2149, NULL, 148.00, '2025-11-02 08:08:16'),
(2150, NULL, 355.00, '2025-11-02 16:30:57'),
(2151, 6, 132.00, '2025-11-02 13:50:22'),
(2152, 5, 372.00, '2025-11-02 07:52:34'),
(2153, 5, 132.00, '2025-11-02 09:44:30'),
(2154, 5, 67.00, '2025-11-02 06:32:09'),
(2155, 6, 326.00, '2025-11-02 14:13:53'),
(2156, 5, 125.00, '2025-11-02 19:45:31'),
(2157, 5, 339.00, '2025-11-02 06:13:25'),
(2158, 6, 505.00, '2025-11-02 11:40:27'),
(2159, 5, 40.00, '2025-11-02 11:19:55'),
(2160, 5, 91.00, '2025-11-02 19:55:29'),
(2161, NULL, 510.00, '2025-11-02 11:11:43'),
(2162, NULL, 348.00, '2025-11-03 17:14:38'),
(2163, 5, 342.00, '2025-11-03 08:17:41'),
(2164, 5, 439.00, '2025-11-03 09:38:06'),
(2165, NULL, 327.00, '2025-11-03 12:02:22'),
(2166, 5, 314.00, '2025-11-03 07:22:49'),
(2167, NULL, 277.00, '2025-11-03 18:29:56'),
(2168, NULL, 60.00, '2025-11-03 16:28:58'),
(2169, 6, 283.00, '2025-11-03 19:31:30'),
(2170, 5, 80.00, '2025-11-03 08:40:21'),
(2171, 5, 378.00, '2025-11-03 08:25:01'),
(2172, NULL, 130.00, '2025-11-03 13:56:08'),
(2173, 6, 393.00, '2025-11-03 09:52:17'),
(2174, 6, 366.00, '2025-11-03 08:28:50'),
(2175, NULL, 271.00, '2025-11-04 17:52:37'),
(2176, 6, 98.00, '2025-11-04 15:08:17'),
(2177, 6, 477.00, '2025-11-04 18:25:01'),
(2178, NULL, 184.00, '2025-11-04 10:37:33'),
(2179, 6, 440.00, '2025-11-04 15:53:01'),
(2180, 5, 343.00, '2025-11-04 17:26:27'),
(2181, NULL, 207.00, '2025-11-04 09:19:54'),
(2182, NULL, 195.00, '2025-11-04 17:20:56'),
(2183, NULL, 199.00, '2025-11-04 13:08:58'),
(2184, 6, 12.00, '2025-11-04 19:25:25'),
(2185, NULL, 118.00, '2025-11-04 07:51:44'),
(2186, NULL, 90.00, '2025-11-04 06:43:09'),
(2187, NULL, 321.00, '2025-11-04 14:56:51'),
(2188, 5, 109.00, '2025-11-04 15:35:27'),
(2189, 6, 100.00, '2025-11-04 17:02:37'),
(2190, 5, 249.00, '2025-11-04 10:34:51'),
(2191, NULL, 275.00, '2025-11-04 12:58:17'),
(2192, 6, 135.00, '2025-11-04 07:57:48'),
(2193, 6, 40.00, '2025-11-04 15:07:17'),
(2194, 6, 297.00, '2025-11-04 16:35:41'),
(2195, 5, 186.00, '2025-11-04 06:31:57'),
(2196, 6, 225.00, '2025-11-04 15:12:07'),
(2197, NULL, 54.00, '2025-11-05 06:19:03'),
(2198, NULL, 15.00, '2025-11-05 12:19:01'),
(2199, 5, 261.00, '2025-11-05 10:05:19'),
(2200, NULL, 126.00, '2025-11-05 19:03:50'),
(2201, 5, 336.00, '2025-11-05 13:44:51'),
(2202, 6, 390.00, '2025-11-05 18:00:19'),
(2203, NULL, 446.00, '2025-11-05 11:28:25'),
(2204, NULL, 485.00, '2025-11-05 12:20:18'),
(2205, NULL, 43.00, '2025-11-05 14:49:37'),
(2206, 5, 146.00, '2025-11-05 13:18:25'),
(2207, 5, 75.00, '2025-11-05 09:03:32'),
(2208, NULL, 92.00, '2025-11-05 18:16:18'),
(2209, 5, 372.00, '2025-11-05 19:32:17'),
(2210, 6, 318.00, '2025-11-05 15:31:16'),
(2211, NULL, 176.00, '2025-11-05 08:14:16'),
(2212, 5, 45.00, '2025-11-05 13:39:10'),
(2213, 6, 251.00, '2025-11-05 08:02:18'),
(2214, 5, 332.00, '2025-11-05 10:44:51'),
(2215, 6, 256.00, '2025-11-06 11:26:44'),
(2216, NULL, 485.00, '2025-11-06 19:52:20'),
(2217, NULL, 20.00, '2025-11-06 10:03:13'),
(2218, NULL, 365.00, '2025-11-06 11:20:39'),
(2219, NULL, 151.00, '2025-11-06 17:15:18'),
(2220, NULL, 321.00, '2025-11-06 19:48:11'),
(2221, NULL, 361.00, '2025-11-06 07:24:59'),
(2222, 5, 266.00, '2025-11-06 14:11:50'),
(2223, 6, 254.00, '2025-11-06 07:33:04'),
(2224, 5, 244.00, '2025-11-06 19:50:40'),
(2225, 6, 252.00, '2025-11-06 16:14:51'),
(2226, 6, 492.00, '2025-11-06 11:19:02'),
(2227, NULL, 111.00, '2025-11-07 12:54:28'),
(2228, NULL, 293.00, '2025-11-07 18:33:07'),
(2229, NULL, 68.00, '2025-11-07 09:01:02'),
(2230, NULL, 491.00, '2025-11-07 13:47:27'),
(2231, 5, 155.00, '2025-11-07 12:23:17'),
(2232, 5, 93.00, '2025-11-07 17:36:34'),
(2233, NULL, 259.00, '2025-11-07 09:18:32'),
(2234, 6, 427.00, '2025-11-07 18:27:05'),
(2235, 5, 8.00, '2025-11-07 17:13:40'),
(2236, 5, 335.00, '2025-11-07 11:53:38'),
(2237, NULL, 232.00, '2025-11-07 10:11:57'),
(2238, 5, 391.00, '2025-11-07 09:05:02'),
(2239, NULL, 379.00, '2025-11-07 09:07:57'),
(2240, 6, 140.00, '2025-11-07 11:24:54'),
(2241, 6, 453.00, '2025-11-07 09:16:17'),
(2242, 6, 291.00, '2025-11-08 16:15:13'),
(2243, 6, 100.00, '2025-11-08 12:00:09'),
(2244, 6, 124.00, '2025-11-08 15:55:40'),
(2245, NULL, 90.00, '2025-11-08 13:49:52'),
(2246, 6, 199.00, '2025-11-08 18:56:39'),
(2247, NULL, 304.00, '2025-11-08 13:04:39'),
(2248, 6, 264.00, '2025-11-08 16:21:25'),
(2249, NULL, 10.00, '2025-11-08 15:16:27'),
(2250, NULL, 272.00, '2025-11-08 12:18:02'),
(2251, 5, 329.00, '2025-11-08 19:55:02'),
(2252, NULL, 261.00, '2025-11-08 18:14:18'),
(2253, 6, 181.00, '2025-11-08 12:10:39'),
(2254, NULL, 121.00, '2025-11-08 14:38:46'),
(2255, 6, 276.00, '2025-11-08 12:16:08'),
(2256, 6, 300.00, '2025-11-08 17:01:27'),
(2257, NULL, 376.00, '2025-11-08 10:21:19'),
(2258, 5, 199.00, '2025-11-08 17:23:41'),
(2259, 5, 328.00, '2025-11-08 16:23:31'),
(2260, 6, 506.00, '2025-11-08 15:00:48'),
(2261, 6, 272.00, '2025-11-08 13:47:52'),
(2262, NULL, 134.00, '2025-11-09 10:14:07'),
(2263, NULL, 359.00, '2025-11-09 12:34:25'),
(2264, 6, 367.00, '2025-11-09 11:33:58'),
(2265, 6, 570.00, '2025-11-09 07:20:05'),
(2266, NULL, 378.00, '2025-11-09 18:27:21'),
(2267, 6, 40.00, '2025-11-09 06:10:42'),
(2268, 5, 48.00, '2025-11-09 07:13:43'),
(2269, 6, 269.00, '2025-11-09 13:30:07'),
(2270, 5, 220.00, '2025-11-09 10:55:01'),
(2271, 6, 95.00, '2025-11-09 11:55:03'),
(2272, 5, 301.00, '2025-11-09 08:56:19'),
(2273, 5, 269.00, '2025-11-09 12:13:10'),
(2274, 6, 61.00, '2025-11-09 10:41:36'),
(2275, NULL, 257.00, '2025-11-09 10:21:47'),
(2276, 5, 310.00, '2025-11-10 14:29:00'),
(2277, 5, 50.00, '2025-11-10 07:41:31'),
(2278, 5, 110.00, '2025-11-10 15:43:30'),
(2279, 5, 166.00, '2025-11-10 13:38:37'),
(2280, 5, 156.00, '2025-11-10 13:15:54'),
(2281, 5, 395.00, '2025-11-10 10:55:23'),
(2282, NULL, 220.00, '2025-11-10 10:56:34'),
(2283, 6, 345.00, '2025-11-10 16:30:31'),
(2284, 6, 100.00, '2025-11-10 10:55:12'),
(2285, 5, 260.00, '2025-11-10 07:57:39'),
(2286, 5, 240.00, '2025-11-10 15:12:24'),
(2287, 5, 166.00, '2025-11-10 10:09:40'),
(2288, 6, 89.00, '2025-11-10 09:50:55'),
(2289, 6, 385.00, '2025-11-10 18:16:53'),
(2290, 6, 95.00, '2025-11-10 10:06:36'),
(2291, 6, 241.00, '2025-11-10 07:12:45'),
(2292, 5, 164.00, '2025-11-10 13:17:40'),
(2293, 6, 531.00, '2025-11-10 07:13:26'),
(2294, NULL, 110.00, '2025-11-10 14:42:42'),
(2295, 5, 64.00, '2025-11-10 06:33:21'),
(2296, 5, 190.00, '2025-11-10 17:53:57'),
(2297, 6, 88.00, '2025-11-10 16:24:14'),
(2298, 6, 126.00, '2025-11-10 18:25:31'),
(2299, 5, 303.00, '2025-11-10 19:44:22'),
(2300, 6, 416.00, '2025-11-11 07:08:28'),
(2301, 6, 100.00, '2025-11-11 12:40:01'),
(2302, 5, 266.00, '2025-11-11 18:10:27'),
(2303, NULL, 171.00, '2025-11-11 18:42:37'),
(2304, 6, 405.00, '2025-11-11 11:23:15'),
(2305, 6, 401.00, '2025-11-11 09:19:19'),
(2306, NULL, 218.00, '2025-11-11 10:22:52'),
(2307, 6, 375.00, '2025-11-11 11:27:43'),
(2308, NULL, 266.00, '2025-11-11 12:14:09'),
(2309, 5, 92.00, '2025-11-11 18:41:19'),
(2310, 5, 475.00, '2025-11-11 07:34:07'),
(2311, 5, 36.00, '2025-11-11 09:15:08'),
(2312, 6, 254.00, '2025-11-11 16:36:30'),
(2313, 6, 453.00, '2025-11-11 13:21:05'),
(2314, NULL, 260.00, '2025-11-11 10:51:15'),
(2315, 5, 359.00, '2025-11-11 19:13:48'),
(2316, 6, 184.00, '2025-11-12 14:42:53'),
(2317, 6, 122.00, '2025-11-12 06:14:11'),
(2318, 6, 378.00, '2025-11-12 11:18:02'),
(2319, NULL, 76.00, '2025-11-12 15:30:22'),
(2320, NULL, 513.00, '2025-11-12 11:37:43'),
(2321, 6, 185.00, '2025-11-12 14:38:38'),
(2322, NULL, 144.00, '2025-11-12 12:42:01'),
(2323, NULL, 426.00, '2025-11-12 10:06:55'),
(2324, NULL, 385.00, '2025-11-12 15:54:49'),
(2325, 6, 184.00, '2025-11-12 09:15:13'),
(2326, 5, 356.00, '2025-11-12 15:05:01'),
(2327, 5, 378.00, '2025-11-12 16:13:07'),
(2328, 5, 40.00, '2025-11-12 14:36:34'),
(2329, 6, 30.00, '2025-11-12 07:44:55'),
(2330, 5, 486.00, '2025-11-12 13:09:21'),
(2331, 6, 90.00, '2025-11-13 17:41:57'),
(2332, 6, 543.00, '2025-11-13 19:22:38'),
(2333, 6, 263.00, '2025-11-13 11:34:01'),
(2334, 5, 115.00, '2025-11-13 19:44:10'),
(2335, 5, 46.00, '2025-11-13 11:16:59'),
(2336, 6, 344.00, '2025-11-13 17:09:03'),
(2337, 6, 286.00, '2025-11-13 10:16:54'),
(2338, 5, 12.00, '2025-11-13 19:18:58'),
(2339, 5, 168.00, '2025-11-13 08:40:35'),
(2340, 6, 112.00, '2025-11-13 14:18:06'),
(2341, 5, 151.00, '2025-11-13 10:39:46'),
(2342, 5, 56.00, '2025-11-13 18:40:08'),
(2343, NULL, 463.00, '2025-11-13 13:48:25'),
(2344, 6, 20.00, '2025-11-13 13:26:29'),
(2345, 6, 92.00, '2025-11-13 16:02:07'),
(2346, NULL, 116.00, '2025-11-13 19:54:06'),
(2347, 6, 135.00, '2025-11-13 10:38:57'),
(2348, 6, 110.00, '2025-11-13 10:25:12'),
(2349, 6, 367.00, '2025-11-13 11:46:33'),
(2350, 6, 300.00, '2025-11-13 13:49:24'),
(2351, 6, 333.00, '2025-11-13 18:37:45'),
(2352, NULL, 103.00, '2025-11-14 15:49:55'),
(2353, 6, 52.00, '2025-11-14 07:19:00'),
(2354, 5, 434.00, '2025-11-14 14:22:28'),
(2355, NULL, 376.00, '2025-11-14 08:30:26'),
(2356, NULL, 96.00, '2025-11-14 08:18:44'),
(2357, 6, 168.00, '2025-11-14 11:11:48'),
(2358, NULL, 438.00, '2025-11-14 10:55:50'),
(2359, 5, 174.00, '2025-11-14 18:51:50'),
(2360, 5, 505.00, '2025-11-14 14:06:08'),
(2361, NULL, 277.00, '2025-11-14 08:27:48'),
(2362, NULL, 558.00, '2025-11-14 13:52:21'),
(2363, NULL, 180.00, '2025-11-14 17:41:13'),
(2364, 5, 119.00, '2025-11-14 07:33:34'),
(2365, NULL, 177.00, '2025-11-14 09:32:49'),
(2366, NULL, 298.00, '2025-11-15 07:47:03'),
(2367, 6, 211.00, '2025-11-15 16:58:04'),
(2368, 5, 129.00, '2025-11-15 14:35:43'),
(2369, NULL, 229.00, '2025-11-15 11:43:46'),
(2370, NULL, 376.00, '2025-11-15 08:43:18'),
(2371, NULL, 345.00, '2025-11-15 16:01:10'),
(2372, NULL, 250.00, '2025-11-15 07:10:37'),
(2373, 6, 24.00, '2025-11-15 07:33:56'),
(2374, 6, 580.00, '2025-11-15 19:48:17'),
(2375, 5, 414.00, '2025-11-15 17:19:53'),
(2376, 6, 110.00, '2025-11-15 10:19:54'),
(2377, 6, 107.00, '2025-11-15 15:44:34'),
(2378, NULL, 300.00, '2025-11-15 12:06:24'),
(2379, 6, 200.00, '2025-11-16 11:00:33'),
(2380, NULL, 263.00, '2025-11-16 19:05:19'),
(2381, 6, 238.00, '2025-11-16 09:03:35'),
(2382, NULL, 158.00, '2025-11-16 11:12:10'),
(2383, 6, 85.00, '2025-11-16 18:36:36'),
(2384, 6, 335.00, '2025-11-16 13:35:33'),
(2385, NULL, 117.00, '2025-11-16 08:02:49'),
(2386, NULL, 239.00, '2025-11-16 19:55:45'),
(2387, 5, 75.00, '2025-11-16 17:35:32'),
(2388, 5, 104.00, '2025-11-16 11:24:04'),
(2389, NULL, 210.00, '2025-11-16 10:00:11'),
(2390, NULL, 90.00, '2025-11-16 08:58:16'),
(2391, NULL, 80.00, '2025-11-16 15:23:27'),
(2392, 6, 36.00, '2025-11-16 12:52:13'),
(2393, 5, 359.00, '2025-11-16 12:11:25'),
(2394, 6, 100.00, '2025-11-16 06:44:13'),
(2395, 5, 397.00, '2025-11-16 07:25:56'),
(2396, 6, 406.00, '2025-11-16 07:34:26'),
(2397, 5, 94.00, '2025-11-17 07:10:25'),
(2398, NULL, 308.00, '2025-11-17 17:03:52'),
(2399, NULL, 446.00, '2025-11-17 17:55:32'),
(2400, NULL, 391.00, '2025-11-17 09:03:29'),
(2401, NULL, 78.00, '2025-11-17 11:32:44'),
(2402, 6, 326.00, '2025-11-17 13:57:29'),
(2403, NULL, 330.00, '2025-11-17 13:26:07'),
(2404, NULL, 350.00, '2025-11-17 19:04:13'),
(2405, 5, 184.00, '2025-11-17 11:55:02'),
(2406, 5, 190.00, '2025-11-17 14:35:49'),
(2407, 5, 445.00, '2025-11-17 08:13:07'),
(2408, 5, 185.00, '2025-11-17 11:18:02'),
(2409, 6, 100.00, '2025-11-17 07:28:52'),
(2410, NULL, 180.00, '2025-11-17 18:08:12'),
(2411, NULL, 166.00, '2025-11-17 06:31:17'),
(2412, 5, 473.00, '2025-11-17 18:14:24'),
(2413, 5, 75.00, '2025-11-17 08:02:08'),
(2414, NULL, 161.00, '2025-11-17 07:35:14'),
(2415, NULL, 308.00, '2025-11-17 08:18:42'),
(2416, 5, 360.00, '2025-11-17 13:16:09'),
(2417, 5, 250.00, '2025-11-18 10:15:06'),
(2418, 6, 114.00, '2025-11-18 16:40:18'),
(2419, 6, 100.00, '2025-11-18 13:42:09'),
(2420, 6, 50.00, '2025-11-18 06:46:07'),
(2421, NULL, 40.00, '2025-11-18 15:34:10'),
(2422, NULL, 390.00, '2025-11-18 14:34:29'),
(2423, 5, 105.00, '2025-11-18 13:22:55'),
(2424, NULL, 483.00, '2025-11-18 10:37:11'),
(2425, 5, 30.00, '2025-11-18 16:02:27'),
(2426, 6, 343.00, '2025-11-18 08:17:14'),
(2427, NULL, 232.00, '2025-11-18 17:12:25'),
(2428, 5, 274.00, '2025-11-18 07:42:29'),
(2429, 6, 395.00, '2025-11-18 12:19:47'),
(2430, 6, 379.00, '2025-11-18 10:24:06'),
(2431, 6, 486.00, '2025-11-18 13:55:42'),
(2432, 5, 168.00, '2025-11-18 19:27:48'),
(2433, 5, 450.00, '2025-11-18 13:06:18'),
(2434, 6, 40.00, '2025-11-19 06:52:54'),
(2435, NULL, 237.00, '2025-11-19 14:49:55'),
(2436, 5, 467.00, '2025-11-19 11:15:46'),
(2437, 5, 306.00, '2025-11-19 06:19:40'),
(2438, 5, 400.00, '2025-11-19 19:43:53'),
(2439, NULL, 436.00, '2025-11-19 07:23:07'),
(2440, NULL, 398.00, '2025-11-19 14:03:33'),
(2441, 5, 152.00, '2025-11-19 19:34:27'),
(2442, NULL, 501.00, '2025-11-19 09:13:10'),
(2443, 5, 388.00, '2025-11-19 16:51:51'),
(2444, NULL, 497.00, '2025-11-19 09:26:44'),
(2445, 6, 90.00, '2025-11-20 11:14:43'),
(2446, NULL, 342.00, '2025-11-20 08:29:58'),
(2447, 5, 88.00, '2025-11-20 11:38:36'),
(2448, 5, 355.00, '2025-11-20 14:03:24'),
(2449, 5, 322.00, '2025-11-20 15:07:45'),
(2450, 6, 263.00, '2025-11-20 14:49:21'),
(2451, 5, 322.00, '2025-11-20 19:20:00'),
(2452, 6, 521.00, '2025-11-20 09:14:38'),
(2453, 6, 214.00, '2025-11-20 11:15:08'),
(2454, NULL, 126.00, '2025-11-20 11:45:59'),
(2455, NULL, 337.00, '2025-11-20 11:27:24'),
(2456, 5, 312.00, '2025-11-20 15:03:24'),
(2457, 6, 334.00, '2025-11-21 13:22:56'),
(2458, 6, 110.00, '2025-11-21 16:42:12'),
(2459, 6, 403.00, '2025-11-21 10:58:26'),
(2460, 5, 441.00, '2025-11-21 11:02:14'),
(2461, 5, 162.00, '2025-11-21 12:10:04'),
(2462, NULL, 372.00, '2025-11-21 10:10:20'),
(2463, NULL, 142.00, '2025-11-21 09:54:46'),
(2464, 5, 320.00, '2025-11-21 12:19:12'),
(2465, 6, 100.00, '2025-11-21 17:14:48'),
(2466, NULL, 352.00, '2025-11-21 13:55:44'),
(2467, 6, 491.00, '2025-11-21 08:00:30'),
(2468, 6, 93.00, '2025-11-21 06:22:38'),
(2469, 5, 584.00, '2025-11-21 14:14:13'),
(2470, NULL, 372.00, '2025-11-21 19:54:47'),
(2471, 6, 126.00, '2025-11-21 09:55:33'),
(2472, 6, 151.00, '2025-11-22 08:57:11'),
(2473, 6, 165.00, '2025-11-22 14:03:13'),
(2474, NULL, 378.00, '2025-11-22 06:02:37'),
(2475, 6, 285.00, '2025-11-22 19:15:51'),
(2476, NULL, 435.00, '2025-11-22 08:18:46'),
(2477, 5, 148.00, '2025-11-22 16:38:02'),
(2478, 6, 175.00, '2025-11-22 06:15:21'),
(2479, NULL, 20.00, '2025-11-22 18:33:47'),
(2480, 5, 109.00, '2025-11-22 17:24:22'),
(2481, 6, 406.00, '2025-11-22 18:20:40'),
(2482, 6, 174.00, '2025-11-22 17:15:20'),
(2483, NULL, 168.00, '2025-11-22 17:28:26'),
(2484, NULL, 124.00, '2025-11-22 11:35:59'),
(2485, 6, 259.00, '2025-11-22 06:59:03'),
(2486, 6, 96.00, '2025-11-22 13:18:46'),
(2487, 6, 548.00, '2025-11-22 11:32:59'),
(2488, 5, 135.00, '2025-11-22 11:21:13'),
(2489, NULL, 292.00, '2025-11-22 13:23:37'),
(2490, 6, 252.00, '2025-11-22 17:57:05'),
(2491, 5, 223.00, '2025-11-22 06:04:33'),
(2492, 5, 247.00, '2025-11-22 17:57:16'),
(2493, 6, 260.00, '2025-11-23 17:22:55'),
(2494, 5, 249.00, '2025-11-23 15:39:35'),
(2495, NULL, 415.00, '2025-11-23 14:13:39'),
(2496, 6, 421.00, '2025-11-23 11:57:26'),
(2497, 6, 544.00, '2025-11-23 09:58:54'),
(2498, 5, 115.00, '2025-11-23 12:10:17'),
(2499, 6, 188.00, '2025-11-23 12:02:20'),
(2500, NULL, 38.00, '2025-11-23 06:55:32'),
(2501, 5, 414.00, '2025-11-23 10:45:07'),
(2502, 5, 104.00, '2025-11-23 17:48:50'),
(2503, 5, 90.00, '2025-11-23 09:57:17'),
(2504, 6, 58.00, '2025-11-23 17:24:46'),
(2505, 5, 433.00, '2025-11-23 10:47:31'),
(2506, NULL, 287.00, '2025-11-23 18:54:55'),
(2507, 6, 123.00, '2025-11-23 17:36:14'),
(2508, NULL, 102.00, '2025-11-23 16:31:10'),
(2509, 6, 39.00, '2025-11-23 14:55:34'),
(2510, 5, 224.00, '2025-11-23 10:23:38'),
(2511, 5, 478.00, '2025-11-23 17:57:42'),
(2512, NULL, 140.00, '2025-11-23 16:47:14'),
(2513, 5, 40.00, '2025-11-23 16:55:14'),
(2514, 6, 99.00, '2025-11-23 10:07:44'),
(2515, 5, 216.00, '2025-11-23 15:52:11'),
(2516, 5, 175.00, '2025-11-24 16:16:16'),
(2517, 6, 100.00, '2025-11-24 08:16:34'),
(2518, 5, 100.00, '2025-11-24 08:01:48'),
(2519, NULL, 399.00, '2025-11-24 14:16:50'),
(2520, NULL, 256.00, '2025-11-24 10:44:35'),
(2521, 6, 471.00, '2025-11-24 14:50:20'),
(2522, NULL, 35.00, '2025-11-24 15:00:08'),
(2523, 6, 398.00, '2025-11-24 19:09:00'),
(2524, NULL, 295.00, '2025-11-24 16:01:40'),
(2525, NULL, 375.00, '2025-11-24 06:29:27'),
(2526, 5, 352.00, '2025-11-24 15:52:53'),
(2527, 6, 217.00, '2025-11-24 13:05:40'),
(2528, 6, 302.00, '2025-11-24 14:01:31'),
(2529, 6, 388.00, '2025-11-24 08:52:03'),
(2530, NULL, 338.00, '2025-11-24 09:53:57'),
(2531, 6, 86.00, '2025-11-24 08:09:46'),
(2532, 6, 435.00, '2025-11-24 10:55:02'),
(2533, 5, 45.00, '2025-11-25 13:31:44'),
(2534, NULL, 25.00, '2025-11-25 08:33:31'),
(2535, NULL, 35.00, '2025-11-25 09:40:36'),
(2536, NULL, 522.00, '2025-11-25 10:11:24'),
(2537, 5, 275.00, '2025-11-25 08:57:42'),
(2538, 6, 150.00, '2025-11-25 15:10:46'),
(2539, 5, 301.00, '2025-11-25 12:56:06'),
(2540, NULL, 235.00, '2025-11-25 15:03:18'),
(2541, NULL, 36.00, '2025-11-25 06:30:59'),
(2542, NULL, 466.00, '2025-11-25 13:17:56'),
(2543, NULL, 373.00, '2025-11-25 18:11:30'),
(2544, NULL, 188.00, '2025-11-25 15:38:03'),
(2545, 5, 97.00, '2025-11-25 09:07:30'),
(2546, 6, 216.00, '2025-11-25 10:19:49'),
(2547, 6, 232.00, '2025-11-25 06:01:01'),
(2548, 3, 18.00, '2025-11-25 21:54:53'),
(2549, 4, 90.00, '2025-11-25 22:01:18'),
(2550, 4, 35.00, '2025-11-26 15:10:11'),
(2551, 3, 29.60, '2025-11-27 10:49:25'),
(2552, 3, 75.00, '2025-11-27 11:11:06'),
(2553, 3, 80.75, '2025-11-29 21:08:01'),
(2554, 3, 126.00, '2025-11-29 21:27:47'),
(2555, 3, 34.20, '2025-11-30 10:58:00'),
(2556, 3, 47.70, '2025-11-30 14:55:08'),
(2557, 3, 127.50, '2025-11-30 15:56:13'),
(2558, 3, 19.00, '2025-12-01 09:33:38'),
(2559, 3, 0.00, '2025-12-03 10:12:31'),
(2560, 3, 39.90, '2025-12-04 10:24:09'),
(2561, 3, 32.00, '2025-12-05 09:29:50'),
(2562, 3, 75.00, '2025-12-05 14:07:53'),
(2563, 3, 90.40, '2025-12-05 14:26:55'),
(2564, 3, 910.40, '2025-12-05 14:33:51'),
(2565, 3, 293.26, '2025-12-05 14:52:34'),
(2566, 3, 72.00, '2025-12-05 16:18:37'),
(2567, 3, 553.00, '2025-12-05 16:32:29');

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
(25, 3, 'email_token', NULL, '453184', '2025-11-20 00:01:56', 0),
(26, 3, 'phone_otp', NULL, '742965', '2025-12-01 22:21:37', 1),
(27, 3, 'phone_otp', NULL, '389622', '2025-12-01 22:29:14', 1),
(28, 3, 'phone_otp', NULL, '748033', '2025-12-03 10:22:02', 1),
(29, 3, 'email_token', NULL, '706523', '2025-12-03 10:33:50', 1);

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
(12, 22, 2, '2025-11-21'),
(13, 22, 25, '2025-11-30'),
(14, 24, 2, '2025-12-03'),
(15, 33, 1, '2025-12-03'),
(16, 22, 2, '2025-12-05'),
(17, 22, 1, '2025-12-05'),
(18, 24, 6, '2025-12-05'),
(19, 33, 2, '2025-12-05'),
(20, 33, 5, '2025-12-05'),
(21, 2, 2, '2025-12-05'),
(22, 22, 1, '2025-12-05'),
(23, 22, 1, '2025-12-05'),
(24, 2, 2, '2025-12-05');

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
(1, 'Spanish Bread', 10.00, NULL, 'available', 20, 'pcs', 1, 24),
(2, 'Cheese Bread', 12.00, '../uploads/products/prod_6912d9f7dd8542.44340855.jpg', 'available', 4, 'pcs', 1, 25),
(3, 'Ensaymada', 15.00, NULL, 'available', 20, 'pcs', 1, 12),
(4, 'Cinnamon Roll', 20.00, NULL, 'available', 10, 'pcs', 1, 12),
(5, 'Choco Bread', 12.00, '../uploads/products/prod_6912dc4b078fa3.44458814.jpg', 'available', 50, 'pcs', 1, 24),
(6, 'Ube Cheese Pandesal', 15.00, NULL, 'available', 29, 'pcs', 1, 30),
(7, 'Hotdog Roll', 18.00, NULL, 'available', 8, 'pcs', 1, 1),
(8, 'Cheese Stick Bread', 10.00, '../uploads/products/prod_6912da69d6bf68.30804241.jpg', 'available', 24, 'pcs', 1, 1),
(9, 'Tuna Bun', 20.00, NULL, 'available', 13, 'pcs', 1, 1),
(10, 'Egg Pie Slice', 25.00, NULL, 'discontinued', 5, 'pcs', 1, 1),
(11, 'Mocha Bun', 12.00, NULL, 'available', 33, 'pcs', 1, 1),
(12, 'Corned Beef Bread', 20.00, NULL, 'available', 0, 'pcs', 1, 1),
(13, 'Chicken Floss Bun', 25.00, '../uploads/products/prod_6912dc2989ccb1.13733051.jpg', 'available', 27, 'pcs', 1, 1),
(14, 'Chocolate Donut', 18.00, NULL, 'available', 15, 'pcs', 1, 1),
(16, 'Cream Bread', 10.00, NULL, 'available', 48, 'pcs', 1, 1),
(17, 'Coffee Bun', 15.00, NULL, 'available', 28, 'pcs', 1, 1),
(18, 'Garlic Bread', 12.00, NULL, 'available', 20, 'pcs', 1, 3),
(19, 'Milky Loaf', 35.00, NULL, 'available', 15, 'loaf', 1, 1),
(20, 'Whole Wheat Loaf', 40.00, NULL, 'available', 14, 'loaf', 1, 1),
(21, 'Raisin Bread', 20.00, NULL, 'available', 15, 'pcs', 1, 1),
(22, 'Banana Loaf', 35.00, '../uploads/products/prod_6912da909c02d2.10638207.jpg', 'available', 9, 'loaf', 1, 1),
(23, 'Cheese Cupcake', 15.00, '../uploads/products/prod_6912da1b2c3e28.82878651.jpg', 'available', 0, 'pcs', 1, 1),
(24, 'Butter Muffin', 18.00, '../uploads/products/prod_6912d9d87feab5.21908923.jpg', 'available', 5, 'pcs', 1, 1),
(25, 'Yema Bread', 12.00, NULL, 'available', 30, 'pcs', 1, 1),
(26, 'Chocolate Crinkles', 10.00, '../uploads/products/prod_6912dce63c5352.95058142.jpg', 'available', 0, 'pcs', 1, 1),
(27, 'Pan de Coco', 12.00, NULL, 'available', 0, 'pcs', 1, 1),
(28, 'Baguette', 30.00, '../uploads/products/prod_6912d96d877162.80266620.jpg', 'available', 5, 'pcs', 1, 1),
(29, 'Focaccia Bread', 28.00, NULL, 'discontinued', 0, 'pcs', 1, 1),
(30, 'Mini Donut', 8.00, NULL, 'available', 30, 'pcs', 1, 1),
(31, 'Pandesal', 2.00, '../uploads/products/prod_691b0d3c93d025.68910842.jpg', 'available', 7, 'pcs', 1, 1),
(32, 'Garlic Cheese', 10.00, NULL, 'available', 0, 'pcs', 1, 1),
(33, 'Choco German', 5.00, NULL, 'available', 7, 'pcs', 1, 1);

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
(208, 31, 7, 200, 'g'),
(209, 2, 9, 100, 'g'),
(210, 4, 8, 200, 'g'),
(211, 11, 7, 200, 'g'),
(212, 33, 7, 500, 'g'),
(213, 22, 34, 15, 'g'),
(214, 18, 7, 1500, 'g'),
(215, 13, 27, 500, 'g'),
(217, 33, 29, 1, 'kg');

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
(1, 16, 14, 3, 1, 16.20, 'Spoiled Item', '2025-11-25 20:24:27'),
(2, 35, 24, 5, 1, 18.00, 'Spoiled', '2025-10-27 16:21:30'),
(3, 331, 18, 6, 1, 12.00, 'Damaged', '2025-10-29 07:57:02'),
(4, 383, 11, 2, 1, 12.00, 'Spoiled', '2025-10-30 08:32:02'),
(5, 564, 26, 5, 1, 10.00, 'Damaged', '2025-11-01 19:51:22'),
(6, 576, 11, 2, 1, 12.00, 'Damaged', '2025-11-01 08:01:01'),
(7, 623, 4, 5, 1, 20.00, 'Damaged', '2025-11-01 16:58:16'),
(8, 822, 24, 2, 1, 18.00, 'Wrong Item', '2025-11-04 18:23:37'),
(9, 825, 10, 2, 1, 25.00, 'Wrong Item', '2025-11-04 18:27:37'),
(10, 833, 18, 6, 1, 12.00, 'Wrong Item', '2025-11-04 19:09:01'),
(11, 1046, 5, 2, 1, 12.00, 'Damaged', '2025-11-06 20:21:20'),
(12, 1099, 8, 6, 1, 10.00, 'Wrong Item', '2025-11-06 07:35:04'),
(13, 1148, 14, 2, 1, 18.00, 'Wrong Item', '2025-11-07 13:54:27'),
(14, 1158, 25, 5, 1, 12.00, 'Damaged', '2025-11-07 17:45:34'),
(15, 1574, 26, 5, 1, 10.00, 'Damaged', '2025-11-11 18:16:27'),
(16, 1654, 31, 6, 1, 2.00, 'Damaged', '2025-11-11 14:13:05'),
(17, 1713, 12, 2, 1, 20.00, 'Spoiled', '2025-11-12 13:26:01'),
(18, 1745, 7, 6, 1, 18.00, 'Wrong Item', '2025-11-12 09:37:13'),
(19, 1850, 7, 2, 1, 18.00, 'Spoiled', '2025-11-13 14:02:25'),
(20, 1952, 6, 5, 1, 15.00, 'Spoiled', '2025-11-14 14:39:08'),
(21, 2052, 24, 5, 1, 18.00, 'Damaged', '2025-11-15 17:35:53'),
(22, 2083, 25, 2, 1, 12.00, 'Wrong Item', '2025-11-16 19:11:19'),
(23, 2147, 26, 5, 1, 10.00, 'Damaged', '2025-11-16 07:45:56'),
(24, 2208, 2, 6, 1, 12.00, 'Wrong Item', '2025-11-17 13:59:29'),
(25, 2248, 22, 5, 1, 35.00, 'Wrong Item', '2025-11-17 08:16:07'),
(26, 2325, 2, 2, 1, 12.00, 'Spoiled', '2025-11-18 15:10:29'),
(27, 2479, 19, 5, 1, 35.00, 'Spoiled', '2025-11-19 20:28:27'),
(28, 2519, 3, 2, 1, 15.00, 'Spoiled', '2025-11-20 09:24:58'),
(29, 2660, 17, 5, 1, 15.00, 'Wrong Item', '2025-11-21 12:35:12'),
(30, 2701, 23, 5, 1, 15.00, 'Spoiled', '2025-11-21 14:21:13'),
(31, 2753, 31, 6, 1, 2.00, 'Spoiled', '2025-11-22 07:09:21'),
(32, 2761, 10, 5, 1, 25.00, 'Wrong Item', '2025-11-22 17:53:22'),
(33, 2764, 11, 6, 1, 12.00, 'Spoiled', '2025-11-22 18:53:40'),
(34, 2937, 2, 2, 1, 12.00, 'Spoiled', '2025-11-23 17:28:10'),
(35, 3026, 13, 2, 1, 25.00, 'Damaged', '2025-11-24 16:12:40'),
(36, 3073, 11, 2, 1, 12.00, 'Damaged', '2025-11-24 10:49:57'),
(37, 3107, 19, 5, 1, 35.00, 'Damaged', '2025-11-25 09:34:42'),
(38, 3172, 23, 6, 1, 15.00, 'Wrong Item', '2025-11-25 06:36:01'),
(39, 3200, 18, 3, 1, 11.40, 'Iba na ung lasa', '2025-12-03 10:11:01'),
(40, 3203, 33, 3, 1, 4.00, 'Spoiled Item', '2025-12-03 21:45:59'),
(41, 3204, 10, 3, 1, 20.00, 'Spoiled Item', '2025-12-03 23:33:18'),
(42, 3205, 14, 3, 1, 14.40, 'Spoiled Item', '2025-12-03 23:34:11'),
(43, 3201, 11, 3, 1, 11.40, 'Spoiled Item', '2025-12-03 23:35:45'),
(44, 3212, 22, 3, 5, 87.50, 'ayoko', '2025-12-05 14:13:27'),
(45, 3215, 7, 3, 1, 14.40, 'Wrong item', '2025-12-05 14:31:09'),
(46, 3223, 3, 3, 1, 12.90, 'ayoko ', '2025-12-05 15:07:07');

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
(19, 2050, 29, 2, 50.40, 10.00, 0),
(20, 2051, 9, 2, 40.00, 0.00, 0),
(21, 2051, 18, 4, 48.00, 0.00, 0),
(22, 2051, 25, 4, 48.00, 0.00, 0),
(23, 2051, 6, 1, 15.00, 0.00, 0),
(24, 2051, 14, 2, 36.00, 0.00, 0),
(25, 2051, 3, 3, 45.00, 0.00, 0),
(26, 2051, 8, 4, 40.00, 0.00, 0),
(27, 2051, 14, 4, 72.00, 0.00, 0),
(28, 2051, 26, 5, 50.00, 0.00, 0),
(29, 2052, 29, 1, 28.00, 0.00, 0),
(30, 2052, 5, 3, 36.00, 0.00, 0),
(31, 2052, 9, 1, 20.00, 0.00, 0),
(32, 2052, 26, 4, 40.00, 0.00, 0),
(33, 2052, 7, 5, 90.00, 0.00, 0),
(34, 2052, 4, 1, 20.00, 0.00, 0),
(35, 2052, 24, 4, 72.00, 0.00, 1),
(36, 2052, 6, 3, 45.00, 0.00, 0),
(37, 2052, 26, 2, 20.00, 0.00, 0),
(38, 2053, 27, 4, 48.00, 0.00, 0),
(39, 2053, 30, 1, 8.00, 0.00, 0),
(40, 2053, 8, 3, 30.00, 0.00, 0),
(41, 2053, 3, 3, 45.00, 0.00, 0),
(42, 2053, 30, 3, 24.00, 0.00, 0),
(43, 2053, 32, 2, 20.00, 0.00, 0),
(44, 2053, 3, 1, 15.00, 0.00, 0),
(45, 2053, 24, 2, 36.00, 0.00, 0),
(46, 2053, 24, 1, 18.00, 0.00, 0),
(47, 2053, 5, 1, 12.00, 0.00, 0),
(48, 2054, 14, 1, 18.00, 0.00, 0),
(49, 2054, 20, 1, 40.00, 0.00, 0),
(50, 2054, 4, 2, 40.00, 0.00, 0),
(51, 2054, 1, 5, 50.00, 0.00, 0),
(52, 2054, 2, 2, 24.00, 0.00, 0),
(53, 2054, 13, 1, 25.00, 0.00, 0),
(54, 2054, 5, 4, 48.00, 0.00, 0),
(55, 2054, 21, 2, 40.00, 0.00, 0),
(56, 2054, 1, 5, 50.00, 0.00, 0),
(57, 2054, 29, 1, 28.00, 0.00, 0),
(58, 2054, 1, 5, 50.00, 0.00, 0),
(59, 2054, 5, 3, 36.00, 0.00, 0),
(60, 2055, 2, 2, 24.00, 0.00, 0),
(61, 2055, 14, 5, 90.00, 0.00, 0),
(62, 2055, 21, 1, 20.00, 0.00, 0),
(63, 2055, 11, 2, 24.00, 0.00, 0),
(64, 2055, 21, 2, 40.00, 0.00, 0),
(65, 2056, 26, 2, 20.00, 0.00, 0),
(66, 2056, 9, 2, 40.00, 0.00, 0),
(67, 2056, 12, 1, 20.00, 0.00, 0),
(68, 2056, 8, 2, 20.00, 0.00, 0),
(69, 2056, 19, 2, 70.00, 0.00, 0),
(70, 2056, 19, 2, 70.00, 0.00, 0),
(71, 2056, 2, 4, 48.00, 0.00, 0),
(72, 2056, 4, 2, 40.00, 0.00, 0),
(73, 2056, 4, 2, 40.00, 0.00, 0),
(74, 2057, 5, 2, 24.00, 0.00, 0),
(75, 2057, 8, 2, 20.00, 0.00, 0),
(76, 2058, 9, 2, 40.00, 0.00, 0),
(77, 2058, 32, 5, 50.00, 0.00, 0),
(78, 2058, 14, 3, 54.00, 0.00, 0),
(79, 2058, 10, 2, 50.00, 0.00, 0),
(80, 2058, 12, 1, 20.00, 0.00, 0),
(81, 2058, 30, 2, 16.00, 0.00, 0),
(82, 2059, 32, 3, 30.00, 0.00, 0),
(83, 2059, 3, 2, 30.00, 0.00, 0),
(84, 2059, 13, 2, 50.00, 0.00, 0),
(85, 2059, 31, 6, 12.00, 0.00, 0),
(86, 2059, 31, 9, 18.00, 0.00, 0),
(87, 2059, 21, 2, 40.00, 0.00, 0),
(88, 2059, 17, 5, 75.00, 0.00, 0),
(89, 2059, 23, 3, 45.00, 0.00, 0),
(90, 2060, 27, 1, 12.00, 0.00, 0),
(91, 2060, 13, 2, 50.00, 0.00, 0),
(92, 2060, 2, 5, 60.00, 0.00, 0),
(93, 2060, 10, 1, 25.00, 0.00, 0),
(94, 2060, 4, 1, 20.00, 0.00, 0),
(95, 2060, 32, 5, 50.00, 0.00, 0),
(96, 2060, 21, 2, 40.00, 0.00, 0),
(97, 2060, 25, 5, 60.00, 0.00, 0),
(98, 2060, 24, 3, 54.00, 0.00, 0),
(99, 2060, 19, 2, 70.00, 0.00, 0),
(100, 2061, 23, 2, 30.00, 0.00, 0),
(101, 2061, 32, 4, 40.00, 0.00, 0),
(102, 2061, 31, 20, 40.00, 0.00, 0),
(103, 2061, 21, 1, 20.00, 0.00, 0),
(104, 2061, 14, 1, 18.00, 0.00, 0),
(105, 2061, 2, 3, 36.00, 0.00, 0),
(106, 2061, 5, 3, 36.00, 0.00, 0),
(107, 2061, 20, 1, 40.00, 0.00, 0),
(108, 2061, 30, 2, 16.00, 0.00, 0),
(109, 2061, 10, 1, 25.00, 0.00, 0),
(110, 2062, 18, 4, 48.00, 0.00, 0),
(111, 2062, 7, 2, 36.00, 0.00, 0),
(112, 2063, 5, 3, 36.00, 0.00, 0),
(113, 2063, 23, 1, 15.00, 0.00, 0),
(114, 2063, 6, 5, 75.00, 0.00, 0),
(115, 2063, 8, 2, 20.00, 0.00, 0),
(116, 2063, 10, 2, 50.00, 0.00, 0),
(117, 2063, 30, 4, 32.00, 0.00, 0),
(118, 2063, 25, 1, 12.00, 0.00, 0),
(119, 2063, 9, 1, 20.00, 0.00, 0),
(120, 2063, 10, 1, 25.00, 0.00, 0),
(121, 2063, 12, 2, 40.00, 0.00, 0),
(122, 2064, 5, 4, 48.00, 0.00, 0),
(123, 2064, 1, 4, 40.00, 0.00, 0),
(124, 2065, 32, 2, 20.00, 0.00, 0),
(125, 2065, 25, 5, 60.00, 0.00, 0),
(126, 2065, 26, 3, 30.00, 0.00, 0),
(127, 2065, 32, 4, 40.00, 0.00, 0),
(128, 2065, 6, 5, 75.00, 0.00, 0),
(129, 2065, 32, 1, 10.00, 0.00, 0),
(130, 2065, 14, 2, 36.00, 0.00, 0),
(131, 2065, 18, 3, 36.00, 0.00, 0),
(132, 2066, 9, 1, 20.00, 0.00, 0),
(133, 2066, 20, 1, 40.00, 0.00, 0),
(134, 2066, 4, 1, 20.00, 0.00, 0),
(135, 2066, 18, 3, 36.00, 0.00, 0),
(136, 2066, 10, 2, 50.00, 0.00, 0),
(137, 2066, 12, 1, 20.00, 0.00, 0),
(138, 2066, 25, 2, 24.00, 0.00, 0),
(139, 2066, 22, 1, 35.00, 0.00, 0),
(140, 2066, 6, 2, 30.00, 0.00, 0),
(141, 2066, 11, 4, 48.00, 0.00, 0),
(142, 2066, 21, 2, 40.00, 0.00, 0),
(143, 2067, 10, 2, 50.00, 0.00, 0),
(144, 2067, 29, 2, 56.00, 0.00, 0),
(145, 2067, 32, 1, 10.00, 0.00, 0),
(146, 2067, 20, 1, 40.00, 0.00, 0),
(147, 2067, 6, 1, 15.00, 0.00, 0),
(148, 2067, 30, 4, 32.00, 0.00, 0),
(149, 2067, 14, 4, 72.00, 0.00, 0),
(150, 2067, 22, 1, 35.00, 0.00, 0),
(151, 2067, 17, 2, 30.00, 0.00, 0),
(152, 2068, 25, 5, 60.00, 0.00, 0),
(153, 2068, 5, 5, 60.00, 0.00, 0),
(154, 2068, 18, 5, 60.00, 0.00, 0),
(155, 2068, 25, 5, 60.00, 0.00, 0),
(156, 2068, 4, 2, 40.00, 0.00, 0),
(157, 2068, 8, 5, 50.00, 0.00, 0),
(158, 2068, 22, 1, 35.00, 0.00, 0),
(159, 2068, 5, 1, 12.00, 0.00, 0),
(160, 2069, 26, 2, 20.00, 0.00, 0),
(161, 2069, 25, 3, 36.00, 0.00, 0),
(162, 2069, 17, 4, 60.00, 0.00, 0),
(163, 2069, 25, 4, 48.00, 0.00, 0),
(164, 2069, 31, 21, 42.00, 0.00, 0),
(165, 2069, 10, 1, 25.00, 0.00, 0),
(166, 2069, 1, 2, 20.00, 0.00, 0),
(167, 2069, 4, 2, 40.00, 0.00, 0),
(168, 2070, 1, 3, 30.00, 0.00, 0),
(169, 2070, 29, 1, 28.00, 0.00, 0),
(170, 2070, 32, 4, 40.00, 0.00, 0),
(171, 2070, 11, 1, 12.00, 0.00, 0),
(172, 2070, 14, 3, 54.00, 0.00, 0),
(173, 2071, 17, 4, 60.00, 0.00, 0),
(174, 2071, 18, 1, 12.00, 0.00, 0),
(175, 2071, 31, 21, 42.00, 0.00, 0),
(176, 2071, 25, 2, 24.00, 0.00, 0),
(177, 2071, 8, 1, 10.00, 0.00, 0),
(178, 2071, 27, 1, 12.00, 0.00, 0),
(179, 2071, 26, 4, 40.00, 0.00, 0),
(180, 2071, 25, 5, 60.00, 0.00, 0),
(181, 2072, 26, 2, 20.00, 0.00, 0),
(182, 2072, 31, 12, 24.00, 0.00, 0),
(183, 2072, 18, 2, 24.00, 0.00, 0),
(184, 2072, 13, 1, 25.00, 0.00, 0),
(185, 2072, 27, 3, 36.00, 0.00, 0),
(186, 2073, 13, 2, 50.00, 0.00, 0),
(187, 2073, 13, 2, 50.00, 0.00, 0),
(188, 2073, 4, 2, 40.00, 0.00, 0),
(189, 2073, 32, 1, 10.00, 0.00, 0),
(190, 2074, 13, 2, 50.00, 0.00, 0),
(191, 2074, 22, 1, 35.00, 0.00, 0),
(192, 2075, 9, 1, 20.00, 0.00, 0),
(193, 2075, 8, 1, 10.00, 0.00, 0),
(194, 2075, 7, 4, 72.00, 0.00, 0),
(195, 2075, 9, 1, 20.00, 0.00, 0),
(196, 2075, 3, 1, 15.00, 0.00, 0),
(197, 2075, 8, 1, 10.00, 0.00, 0),
(198, 2075, 23, 1, 15.00, 0.00, 0),
(199, 2075, 12, 2, 40.00, 0.00, 0),
(200, 2075, 3, 5, 75.00, 0.00, 0),
(201, 2075, 32, 2, 20.00, 0.00, 0),
(202, 2075, 7, 1, 18.00, 0.00, 0),
(203, 2076, 27, 1, 12.00, 0.00, 0),
(204, 2076, 29, 2, 56.00, 0.00, 0),
(205, 2076, 7, 2, 36.00, 0.00, 0),
(206, 2076, 30, 3, 24.00, 0.00, 0),
(207, 2076, 9, 1, 20.00, 0.00, 0),
(208, 2076, 23, 4, 60.00, 0.00, 0),
(209, 2076, 1, 4, 40.00, 0.00, 0),
(210, 2077, 17, 5, 75.00, 0.00, 0),
(211, 2078, 13, 2, 50.00, 0.00, 0),
(212, 2078, 2, 1, 12.00, 0.00, 0),
(213, 2078, 30, 2, 16.00, 0.00, 0),
(214, 2078, 24, 5, 90.00, 0.00, 0),
(215, 2078, 18, 5, 60.00, 0.00, 0),
(216, 2078, 3, 1, 15.00, 0.00, 0),
(217, 2078, 12, 1, 20.00, 0.00, 0),
(218, 2078, 9, 2, 40.00, 0.00, 0),
(219, 2078, 9, 2, 40.00, 0.00, 0),
(220, 2078, 4, 2, 40.00, 0.00, 0),
(221, 2079, 32, 1, 10.00, 0.00, 0),
(222, 2079, 22, 1, 35.00, 0.00, 0),
(223, 2080, 20, 1, 40.00, 0.00, 0),
(224, 2080, 12, 1, 20.00, 0.00, 0),
(225, 2081, 4, 1, 20.00, 0.00, 0),
(226, 2081, 30, 5, 40.00, 0.00, 0),
(227, 2081, 23, 2, 30.00, 0.00, 0),
(228, 2081, 11, 3, 36.00, 0.00, 0),
(229, 2081, 17, 5, 75.00, 0.00, 0),
(230, 2082, 25, 3, 36.00, 0.00, 0),
(231, 2082, 2, 2, 24.00, 0.00, 0),
(232, 2082, 30, 3, 24.00, 0.00, 0),
(233, 2082, 7, 1, 18.00, 0.00, 0),
(234, 2082, 8, 5, 50.00, 0.00, 0),
(235, 2082, 29, 2, 56.00, 0.00, 0),
(236, 2082, 17, 3, 45.00, 0.00, 0),
(237, 2083, 30, 1, 8.00, 0.00, 0),
(238, 2083, 18, 2, 24.00, 0.00, 0),
(239, 2083, 17, 4, 60.00, 0.00, 0),
(240, 2083, 10, 2, 50.00, 0.00, 0),
(241, 2083, 9, 1, 20.00, 0.00, 0),
(242, 2083, 27, 1, 12.00, 0.00, 0),
(243, 2084, 22, 1, 35.00, 0.00, 0),
(244, 2084, 24, 3, 54.00, 0.00, 0),
(245, 2084, 26, 4, 40.00, 0.00, 0),
(246, 2084, 13, 1, 25.00, 0.00, 0),
(247, 2084, 4, 1, 20.00, 0.00, 0),
(248, 2085, 19, 1, 35.00, 0.00, 0),
(249, 2085, 10, 2, 50.00, 0.00, 0),
(250, 2085, 26, 2, 20.00, 0.00, 0),
(251, 2085, 17, 5, 75.00, 0.00, 0),
(252, 2085, 29, 1, 28.00, 0.00, 0),
(253, 2085, 20, 2, 80.00, 0.00, 0),
(254, 2085, 23, 2, 30.00, 0.00, 0),
(255, 2085, 10, 1, 25.00, 0.00, 0),
(256, 2085, 2, 2, 24.00, 0.00, 0),
(257, 2085, 30, 2, 16.00, 0.00, 0),
(258, 2086, 26, 1, 10.00, 0.00, 0),
(259, 2086, 30, 1, 8.00, 0.00, 0),
(260, 2086, 3, 3, 45.00, 0.00, 0),
(261, 2086, 8, 5, 50.00, 0.00, 0),
(262, 2086, 23, 2, 30.00, 0.00, 0),
(263, 2086, 22, 2, 70.00, 0.00, 0),
(264, 2086, 18, 2, 24.00, 0.00, 0),
(265, 2087, 8, 1, 10.00, 0.00, 0),
(266, 2087, 18, 3, 36.00, 0.00, 0),
(267, 2087, 18, 5, 60.00, 0.00, 0),
(268, 2087, 30, 4, 32.00, 0.00, 0),
(269, 2087, 6, 3, 45.00, 0.00, 0),
(270, 2087, 19, 2, 70.00, 0.00, 0),
(271, 2087, 32, 5, 50.00, 0.00, 0),
(272, 2087, 2, 3, 36.00, 0.00, 0),
(273, 2087, 22, 1, 35.00, 0.00, 0),
(274, 2087, 17, 4, 60.00, 0.00, 0),
(275, 2087, 19, 2, 70.00, 0.00, 0),
(276, 2088, 4, 1, 20.00, 0.00, 0),
(277, 2088, 22, 2, 70.00, 0.00, 0),
(278, 2088, 8, 5, 50.00, 0.00, 0),
(279, 2088, 14, 1, 18.00, 0.00, 0),
(280, 2088, 14, 1, 18.00, 0.00, 0),
(281, 2088, 4, 2, 40.00, 0.00, 0),
(282, 2088, 25, 5, 60.00, 0.00, 0),
(283, 2088, 3, 1, 15.00, 0.00, 0),
(284, 2088, 14, 3, 54.00, 0.00, 0),
(285, 2088, 12, 2, 40.00, 0.00, 0),
(286, 2088, 31, 6, 12.00, 0.00, 0),
(287, 2089, 12, 1, 20.00, 0.00, 0),
(288, 2089, 30, 1, 8.00, 0.00, 0),
(289, 2090, 18, 1, 12.00, 0.00, 0),
(290, 2090, 19, 2, 70.00, 0.00, 0),
(291, 2090, 31, 15, 30.00, 0.00, 0),
(292, 2090, 12, 1, 20.00, 0.00, 0),
(293, 2090, 6, 4, 60.00, 0.00, 0),
(294, 2090, 25, 3, 36.00, 0.00, 0),
(295, 2090, 11, 3, 36.00, 0.00, 0),
(296, 2090, 27, 5, 60.00, 0.00, 0),
(297, 2091, 29, 1, 28.00, 0.00, 0),
(298, 2091, 10, 2, 50.00, 0.00, 0),
(299, 2091, 19, 2, 70.00, 0.00, 0),
(300, 2091, 17, 2, 30.00, 0.00, 0),
(301, 2091, 22, 1, 35.00, 0.00, 0),
(302, 2091, 1, 1, 10.00, 0.00, 0),
(303, 2091, 22, 2, 70.00, 0.00, 0),
(304, 2091, 4, 1, 20.00, 0.00, 0),
(305, 2092, 32, 2, 20.00, 0.00, 0),
(306, 2092, 12, 1, 20.00, 0.00, 0),
(307, 2092, 10, 1, 25.00, 0.00, 0),
(308, 2092, 13, 1, 25.00, 0.00, 0),
(309, 2092, 1, 1, 10.00, 0.00, 0),
(310, 2092, 30, 5, 40.00, 0.00, 0),
(311, 2093, 13, 2, 50.00, 0.00, 0),
(312, 2093, 9, 2, 40.00, 0.00, 0),
(313, 2093, 19, 2, 70.00, 0.00, 0),
(314, 2093, 32, 5, 50.00, 0.00, 0),
(315, 2093, 3, 1, 15.00, 0.00, 0),
(316, 2093, 6, 2, 30.00, 0.00, 0),
(317, 2093, 13, 1, 25.00, 0.00, 0),
(318, 2093, 7, 3, 54.00, 0.00, 0),
(319, 2093, 5, 2, 24.00, 0.00, 0),
(320, 2093, 10, 1, 25.00, 0.00, 0),
(321, 2093, 27, 3, 36.00, 0.00, 0),
(322, 2094, 23, 4, 60.00, 0.00, 0),
(323, 2094, 9, 1, 20.00, 0.00, 0),
(324, 2094, 23, 4, 60.00, 0.00, 0),
(325, 2094, 2, 2, 24.00, 0.00, 0),
(326, 2094, 3, 5, 75.00, 0.00, 0),
(327, 2094, 12, 1, 20.00, 0.00, 0),
(328, 2094, 13, 2, 50.00, 0.00, 0),
(329, 2094, 23, 5, 75.00, 0.00, 0),
(330, 2094, 32, 3, 30.00, 0.00, 0),
(331, 2095, 18, 2, 24.00, 0.00, 1),
(332, 2095, 13, 1, 25.00, 0.00, 0),
(333, 2095, 21, 2, 40.00, 0.00, 0),
(334, 2095, 11, 5, 60.00, 0.00, 0),
(335, 2095, 2, 3, 36.00, 0.00, 0),
(336, 2095, 23, 3, 45.00, 0.00, 0),
(337, 2095, 20, 1, 40.00, 0.00, 0),
(338, 2095, 30, 3, 24.00, 0.00, 0),
(339, 2095, 31, 9, 18.00, 0.00, 0),
(340, 2095, 6, 4, 60.00, 0.00, 0),
(341, 2095, 1, 4, 40.00, 0.00, 0),
(342, 2096, 9, 2, 40.00, 0.00, 0),
(343, 2096, 30, 3, 24.00, 0.00, 0),
(344, 2096, 23, 1, 15.00, 0.00, 0),
(345, 2096, 13, 1, 25.00, 0.00, 0),
(346, 2096, 29, 1, 28.00, 0.00, 0),
(347, 2097, 7, 1, 18.00, 0.00, 0),
(348, 2097, 25, 4, 48.00, 0.00, 0),
(349, 2097, 29, 2, 56.00, 0.00, 0),
(350, 2097, 13, 1, 25.00, 0.00, 0),
(351, 2097, 24, 3, 54.00, 0.00, 0),
(352, 2097, 21, 1, 20.00, 0.00, 0),
(353, 2098, 27, 5, 60.00, 0.00, 0),
(354, 2098, 10, 1, 25.00, 0.00, 0),
(355, 2098, 20, 2, 80.00, 0.00, 0),
(356, 2098, 30, 1, 8.00, 0.00, 0),
(357, 2099, 22, 2, 70.00, 0.00, 0),
(358, 2099, 13, 2, 50.00, 0.00, 0),
(359, 2099, 9, 1, 20.00, 0.00, 0),
(360, 2099, 23, 3, 45.00, 0.00, 0),
(361, 2100, 26, 5, 50.00, 0.00, 0),
(362, 2100, 1, 1, 10.00, 0.00, 0),
(363, 2100, 4, 2, 40.00, 0.00, 0),
(364, 2100, 24, 2, 36.00, 0.00, 0),
(365, 2100, 31, 22, 44.00, 0.00, 0),
(366, 2100, 31, 23, 46.00, 0.00, 0),
(367, 2100, 2, 2, 24.00, 0.00, 0),
(368, 2100, 20, 1, 40.00, 0.00, 0),
(369, 2100, 19, 2, 70.00, 0.00, 0),
(370, 2101, 18, 2, 24.00, 0.00, 0),
(371, 2101, 7, 5, 90.00, 0.00, 0),
(372, 2101, 26, 4, 40.00, 0.00, 0),
(373, 2101, 9, 2, 40.00, 0.00, 0),
(374, 2101, 11, 1, 12.00, 0.00, 0),
(375, 2101, 29, 2, 56.00, 0.00, 0),
(376, 2101, 32, 2, 20.00, 0.00, 0),
(377, 2101, 6, 4, 60.00, 0.00, 0),
(378, 2101, 21, 2, 40.00, 0.00, 0),
(379, 2102, 26, 5, 50.00, 0.00, 0),
(380, 2102, 19, 1, 35.00, 0.00, 0),
(381, 2102, 13, 1, 25.00, 0.00, 0),
(382, 2102, 4, 1, 20.00, 0.00, 0),
(383, 2102, 11, 4, 48.00, 0.00, 1),
(384, 2102, 27, 1, 12.00, 0.00, 0),
(385, 2102, 17, 2, 30.00, 0.00, 0),
(386, 2102, 18, 2, 24.00, 0.00, 0),
(387, 2102, 22, 2, 70.00, 0.00, 0),
(388, 2102, 3, 2, 30.00, 0.00, 0),
(389, 2102, 24, 2, 36.00, 0.00, 0),
(390, 2103, 18, 3, 36.00, 0.00, 0),
(391, 2104, 27, 4, 48.00, 0.00, 0),
(392, 2104, 14, 1, 18.00, 0.00, 0),
(393, 2104, 3, 1, 15.00, 0.00, 0),
(394, 2104, 2, 5, 60.00, 0.00, 0),
(395, 2104, 24, 3, 54.00, 0.00, 0),
(396, 2104, 22, 1, 35.00, 0.00, 0),
(397, 2104, 10, 1, 25.00, 0.00, 0),
(398, 2105, 25, 2, 24.00, 0.00, 0),
(399, 2105, 24, 5, 90.00, 0.00, 0),
(400, 2105, 24, 3, 54.00, 0.00, 0),
(401, 2105, 2, 2, 24.00, 0.00, 0),
(402, 2106, 6, 1, 15.00, 0.00, 0),
(403, 2106, 25, 2, 24.00, 0.00, 0),
(404, 2106, 5, 3, 36.00, 0.00, 0),
(405, 2107, 32, 5, 50.00, 0.00, 0),
(406, 2107, 30, 4, 32.00, 0.00, 0),
(407, 2107, 19, 1, 35.00, 0.00, 0),
(408, 2107, 13, 1, 25.00, 0.00, 0),
(409, 2107, 5, 3, 36.00, 0.00, 0),
(410, 2107, 21, 1, 20.00, 0.00, 0),
(411, 2107, 23, 3, 45.00, 0.00, 0),
(412, 2108, 6, 2, 30.00, 0.00, 0),
(413, 2108, 5, 4, 48.00, 0.00, 0),
(414, 2108, 9, 2, 40.00, 0.00, 0),
(415, 2108, 11, 2, 24.00, 0.00, 0),
(416, 2109, 9, 1, 20.00, 0.00, 0),
(417, 2109, 26, 5, 50.00, 0.00, 0),
(418, 2109, 29, 1, 28.00, 0.00, 0),
(419, 2109, 9, 2, 40.00, 0.00, 0),
(420, 2109, 26, 4, 40.00, 0.00, 0),
(421, 2109, 20, 2, 80.00, 0.00, 0),
(422, 2109, 2, 4, 48.00, 0.00, 0),
(423, 2109, 32, 5, 50.00, 0.00, 0),
(424, 2109, 18, 5, 60.00, 0.00, 0),
(425, 2109, 9, 1, 20.00, 0.00, 0),
(426, 2109, 22, 2, 70.00, 0.00, 0),
(427, 2110, 32, 5, 50.00, 0.00, 0),
(428, 2110, 32, 3, 30.00, 0.00, 0),
(429, 2110, 8, 4, 40.00, 0.00, 0),
(430, 2110, 30, 5, 40.00, 0.00, 0),
(431, 2110, 7, 5, 90.00, 0.00, 0),
(432, 2110, 18, 4, 48.00, 0.00, 0),
(433, 2110, 10, 2, 50.00, 0.00, 0),
(434, 2110, 22, 2, 70.00, 0.00, 0),
(435, 2110, 25, 3, 36.00, 0.00, 0),
(436, 2111, 27, 2, 24.00, 0.00, 0),
(437, 2111, 20, 1, 40.00, 0.00, 0),
(438, 2111, 7, 1, 18.00, 0.00, 0),
(439, 2111, 3, 4, 60.00, 0.00, 0),
(440, 2111, 17, 1, 15.00, 0.00, 0),
(441, 2111, 13, 1, 25.00, 0.00, 0),
(442, 2111, 9, 1, 20.00, 0.00, 0),
(443, 2111, 7, 1, 18.00, 0.00, 0),
(444, 2111, 3, 2, 30.00, 0.00, 0),
(445, 2111, 18, 1, 12.00, 0.00, 0),
(446, 2112, 22, 2, 70.00, 0.00, 0),
(447, 2112, 2, 4, 48.00, 0.00, 0),
(448, 2112, 17, 2, 30.00, 0.00, 0),
(449, 2113, 12, 1, 20.00, 0.00, 0),
(450, 2113, 7, 5, 90.00, 0.00, 0),
(451, 2113, 25, 3, 36.00, 0.00, 0),
(452, 2113, 10, 1, 25.00, 0.00, 0),
(453, 2114, 5, 3, 36.00, 0.00, 0),
(454, 2114, 1, 4, 40.00, 0.00, 0),
(455, 2114, 12, 1, 20.00, 0.00, 0),
(456, 2114, 1, 2, 20.00, 0.00, 0),
(457, 2114, 18, 2, 24.00, 0.00, 0),
(458, 2114, 8, 3, 30.00, 0.00, 0),
(459, 2115, 25, 3, 36.00, 0.00, 0),
(460, 2115, 11, 5, 60.00, 0.00, 0),
(461, 2115, 24, 2, 36.00, 0.00, 0),
(462, 2115, 7, 4, 72.00, 0.00, 0),
(463, 2115, 4, 1, 20.00, 0.00, 0),
(464, 2115, 27, 1, 12.00, 0.00, 0),
(465, 2115, 18, 5, 60.00, 0.00, 0),
(466, 2115, 10, 1, 25.00, 0.00, 0),
(467, 2115, 31, 20, 40.00, 0.00, 0),
(468, 2116, 9, 1, 20.00, 0.00, 0),
(469, 2116, 11, 3, 36.00, 0.00, 0),
(470, 2116, 7, 1, 18.00, 0.00, 0),
(471, 2116, 8, 1, 10.00, 0.00, 0),
(472, 2116, 19, 2, 70.00, 0.00, 0),
(473, 2116, 11, 4, 48.00, 0.00, 0),
(474, 2116, 3, 3, 45.00, 0.00, 0),
(475, 2116, 10, 2, 50.00, 0.00, 0),
(476, 2116, 19, 1, 35.00, 0.00, 0),
(477, 2116, 1, 5, 50.00, 0.00, 0),
(478, 2117, 13, 2, 50.00, 0.00, 0),
(479, 2117, 26, 2, 20.00, 0.00, 0),
(480, 2117, 24, 5, 90.00, 0.00, 0),
(481, 2117, 11, 1, 12.00, 0.00, 0),
(482, 2117, 27, 1, 12.00, 0.00, 0),
(483, 2117, 31, 9, 18.00, 0.00, 0),
(484, 2117, 11, 1, 12.00, 0.00, 0),
(485, 2117, 6, 4, 60.00, 0.00, 0),
(486, 2117, 10, 2, 50.00, 0.00, 0),
(487, 2118, 17, 1, 15.00, 0.00, 0),
(488, 2118, 21, 1, 20.00, 0.00, 0),
(489, 2119, 24, 3, 54.00, 0.00, 0),
(490, 2119, 32, 2, 20.00, 0.00, 0),
(491, 2119, 2, 5, 60.00, 0.00, 0),
(492, 2119, 27, 4, 48.00, 0.00, 0),
(493, 2119, 10, 2, 50.00, 0.00, 0),
(494, 2119, 23, 5, 75.00, 0.00, 0),
(495, 2119, 22, 2, 70.00, 0.00, 0),
(496, 2119, 27, 4, 48.00, 0.00, 0),
(497, 2119, 2, 1, 12.00, 0.00, 0),
(498, 2119, 18, 3, 36.00, 0.00, 0),
(499, 2120, 29, 1, 28.00, 0.00, 0),
(500, 2120, 19, 1, 35.00, 0.00, 0),
(501, 2120, 30, 1, 8.00, 0.00, 0),
(502, 2120, 29, 2, 56.00, 0.00, 0),
(503, 2120, 14, 3, 54.00, 0.00, 0),
(504, 2121, 1, 4, 40.00, 0.00, 0),
(505, 2121, 11, 1, 12.00, 0.00, 0),
(506, 2121, 7, 5, 90.00, 0.00, 0),
(507, 2121, 30, 5, 40.00, 0.00, 0),
(508, 2121, 32, 3, 30.00, 0.00, 0),
(509, 2121, 3, 2, 30.00, 0.00, 0),
(510, 2122, 20, 2, 80.00, 0.00, 0),
(511, 2122, 22, 2, 70.00, 0.00, 0),
(512, 2122, 26, 2, 20.00, 0.00, 0),
(513, 2122, 10, 1, 25.00, 0.00, 0),
(514, 2122, 32, 4, 40.00, 0.00, 0),
(515, 2122, 4, 1, 20.00, 0.00, 0),
(516, 2123, 23, 1, 15.00, 0.00, 0),
(517, 2123, 18, 3, 36.00, 0.00, 0),
(518, 2123, 14, 4, 72.00, 0.00, 0),
(519, 2124, 31, 14, 28.00, 0.00, 0),
(520, 2124, 26, 1, 10.00, 0.00, 0),
(521, 2124, 12, 1, 20.00, 0.00, 0),
(522, 2125, 8, 2, 20.00, 0.00, 0),
(523, 2125, 25, 3, 36.00, 0.00, 0),
(524, 2125, 17, 1, 15.00, 0.00, 0),
(525, 2125, 14, 2, 36.00, 0.00, 0),
(526, 2125, 26, 5, 50.00, 0.00, 0),
(527, 2125, 19, 2, 70.00, 0.00, 0),
(528, 2126, 27, 1, 12.00, 0.00, 0),
(529, 2126, 12, 2, 40.00, 0.00, 0),
(530, 2126, 31, 21, 42.00, 0.00, 0),
(531, 2126, 6, 2, 30.00, 0.00, 0),
(532, 2126, 4, 2, 40.00, 0.00, 0),
(533, 2126, 22, 1, 35.00, 0.00, 0),
(534, 2126, 3, 5, 75.00, 0.00, 0),
(535, 2126, 17, 1, 15.00, 0.00, 0),
(536, 2126, 12, 2, 40.00, 0.00, 0),
(537, 2126, 17, 4, 60.00, 0.00, 0),
(538, 2127, 17, 5, 75.00, 0.00, 0),
(539, 2127, 3, 2, 30.00, 0.00, 0),
(540, 2128, 4, 2, 40.00, 0.00, 0),
(541, 2128, 11, 3, 36.00, 0.00, 0),
(542, 2128, 3, 1, 15.00, 0.00, 0),
(543, 2128, 31, 8, 16.00, 0.00, 0),
(544, 2128, 21, 1, 20.00, 0.00, 0),
(545, 2128, 24, 5, 90.00, 0.00, 0),
(546, 2129, 6, 3, 45.00, 0.00, 0),
(547, 2130, 11, 5, 60.00, 0.00, 0),
(548, 2130, 21, 1, 20.00, 0.00, 0),
(549, 2130, 11, 4, 48.00, 0.00, 0),
(550, 2130, 10, 2, 50.00, 0.00, 0),
(551, 2130, 6, 5, 75.00, 0.00, 0),
(552, 2130, 4, 1, 20.00, 0.00, 0),
(553, 2130, 14, 5, 90.00, 0.00, 0),
(554, 2130, 5, 1, 12.00, 0.00, 0),
(555, 2131, 4, 2, 40.00, 0.00, 0),
(556, 2131, 22, 1, 35.00, 0.00, 0),
(557, 2131, 13, 2, 50.00, 0.00, 0),
(558, 2131, 14, 4, 72.00, 0.00, 0),
(559, 2131, 5, 5, 60.00, 0.00, 0),
(560, 2131, 21, 2, 40.00, 0.00, 0),
(561, 2131, 3, 5, 75.00, 0.00, 0),
(562, 2131, 14, 4, 72.00, 0.00, 0),
(563, 2132, 4, 1, 20.00, 0.00, 0),
(564, 2132, 26, 2, 20.00, 0.00, 1),
(565, 2132, 29, 2, 56.00, 0.00, 0),
(566, 2132, 23, 1, 15.00, 0.00, 0),
(567, 2132, 11, 1, 12.00, 0.00, 0),
(568, 2132, 5, 3, 36.00, 0.00, 0),
(569, 2132, 20, 1, 40.00, 0.00, 0),
(570, 2133, 18, 1, 12.00, 0.00, 0),
(571, 2134, 27, 2, 24.00, 0.00, 0),
(572, 2134, 17, 2, 30.00, 0.00, 0),
(573, 2134, 17, 4, 60.00, 0.00, 0),
(574, 2135, 7, 5, 90.00, 0.00, 0),
(575, 2135, 20, 1, 40.00, 0.00, 0),
(576, 2135, 11, 4, 48.00, 0.00, 1),
(577, 2135, 26, 1, 10.00, 0.00, 0),
(578, 2135, 32, 4, 40.00, 0.00, 0),
(579, 2135, 11, 5, 60.00, 0.00, 0),
(580, 2135, 32, 4, 40.00, 0.00, 0),
(581, 2135, 10, 1, 25.00, 0.00, 0),
(582, 2135, 30, 4, 32.00, 0.00, 0),
(583, 2135, 30, 3, 24.00, 0.00, 0),
(584, 2135, 12, 1, 20.00, 0.00, 0),
(585, 2136, 30, 3, 24.00, 0.00, 0),
(586, 2136, 26, 5, 50.00, 0.00, 0),
(587, 2136, 5, 5, 60.00, 0.00, 0),
(588, 2136, 31, 21, 42.00, 0.00, 0),
(589, 2136, 10, 1, 25.00, 0.00, 0),
(590, 2136, 7, 3, 54.00, 0.00, 0),
(591, 2136, 14, 3, 54.00, 0.00, 0),
(592, 2136, 22, 2, 70.00, 0.00, 0),
(593, 2136, 32, 4, 40.00, 0.00, 0),
(594, 2136, 5, 1, 12.00, 0.00, 0),
(595, 2136, 29, 2, 56.00, 0.00, 0),
(596, 2137, 6, 3, 45.00, 0.00, 0),
(597, 2138, 11, 3, 36.00, 0.00, 0),
(598, 2138, 19, 2, 70.00, 0.00, 0),
(599, 2138, 3, 3, 45.00, 0.00, 0),
(600, 2139, 32, 2, 20.00, 0.00, 0),
(601, 2139, 14, 1, 18.00, 0.00, 0),
(602, 2140, 1, 1, 10.00, 0.00, 0),
(603, 2140, 8, 4, 40.00, 0.00, 0),
(604, 2140, 7, 3, 54.00, 0.00, 0),
(605, 2140, 10, 1, 25.00, 0.00, 0),
(606, 2140, 23, 3, 45.00, 0.00, 0),
(607, 2140, 9, 2, 40.00, 0.00, 0),
(608, 2140, 3, 2, 30.00, 0.00, 0),
(609, 2140, 19, 2, 70.00, 0.00, 0),
(610, 2141, 10, 1, 25.00, 0.00, 0),
(611, 2141, 18, 3, 36.00, 0.00, 0),
(612, 2141, 32, 5, 50.00, 0.00, 0),
(613, 2141, 20, 2, 80.00, 0.00, 0),
(614, 2141, 24, 3, 54.00, 0.00, 0),
(615, 2141, 32, 4, 40.00, 0.00, 0),
(616, 2142, 23, 3, 45.00, 0.00, 0),
(617, 2142, 8, 2, 20.00, 0.00, 0),
(618, 2142, 18, 2, 24.00, 0.00, 0),
(619, 2143, 27, 2, 24.00, 0.00, 0),
(620, 2143, 9, 1, 20.00, 0.00, 0),
(621, 2143, 25, 2, 24.00, 0.00, 0),
(622, 2144, 18, 5, 60.00, 0.00, 0),
(623, 2145, 4, 2, 40.00, 0.00, 1),
(624, 2145, 7, 1, 18.00, 0.00, 0),
(625, 2145, 20, 1, 40.00, 0.00, 0),
(626, 2145, 2, 5, 60.00, 0.00, 0),
(627, 2145, 18, 4, 48.00, 0.00, 0),
(628, 2145, 2, 1, 12.00, 0.00, 0),
(629, 2145, 19, 1, 35.00, 0.00, 0),
(630, 2146, 19, 2, 70.00, 0.00, 0),
(631, 2146, 8, 2, 20.00, 0.00, 0),
(632, 2146, 31, 24, 48.00, 0.00, 0),
(633, 2146, 31, 10, 20.00, 0.00, 0),
(634, 2146, 8, 1, 10.00, 0.00, 0),
(635, 2146, 17, 5, 75.00, 0.00, 0),
(636, 2146, 9, 2, 40.00, 0.00, 0),
(637, 2146, 7, 5, 90.00, 0.00, 0),
(638, 2146, 6, 5, 75.00, 0.00, 0),
(639, 2146, 5, 1, 12.00, 0.00, 0),
(640, 2146, 22, 1, 35.00, 0.00, 0),
(641, 2146, 14, 5, 90.00, 0.00, 0),
(642, 2147, 22, 1, 35.00, 0.00, 0),
(643, 2147, 17, 4, 60.00, 0.00, 0),
(644, 2147, 7, 4, 72.00, 0.00, 0),
(645, 2147, 32, 5, 50.00, 0.00, 0),
(646, 2148, 19, 1, 35.00, 0.00, 0),
(647, 2148, 19, 1, 35.00, 0.00, 0),
(648, 2148, 8, 2, 20.00, 0.00, 0),
(649, 2148, 18, 4, 48.00, 0.00, 0),
(650, 2148, 24, 1, 18.00, 0.00, 0),
(651, 2148, 19, 1, 35.00, 0.00, 0),
(652, 2149, 8, 2, 20.00, 0.00, 0),
(653, 2149, 24, 2, 36.00, 0.00, 0),
(654, 2149, 27, 1, 12.00, 0.00, 0),
(655, 2149, 25, 5, 60.00, 0.00, 0),
(656, 2149, 12, 1, 20.00, 0.00, 0),
(657, 2150, 11, 5, 60.00, 0.00, 0),
(658, 2150, 26, 3, 30.00, 0.00, 0),
(659, 2150, 17, 5, 75.00, 0.00, 0),
(660, 2150, 22, 1, 35.00, 0.00, 0),
(661, 2150, 19, 1, 35.00, 0.00, 0),
(662, 2150, 27, 4, 48.00, 0.00, 0),
(663, 2150, 14, 4, 72.00, 0.00, 0),
(664, 2151, 7, 4, 72.00, 0.00, 0),
(665, 2151, 23, 4, 60.00, 0.00, 0),
(666, 2152, 7, 4, 72.00, 0.00, 0),
(667, 2152, 20, 2, 80.00, 0.00, 0),
(668, 2152, 8, 5, 50.00, 0.00, 0),
(669, 2152, 8, 4, 40.00, 0.00, 0),
(670, 2152, 9, 1, 20.00, 0.00, 0),
(671, 2152, 12, 1, 20.00, 0.00, 0),
(672, 2152, 17, 4, 60.00, 0.00, 0),
(673, 2152, 1, 3, 30.00, 0.00, 0),
(674, 2153, 21, 2, 40.00, 0.00, 0),
(675, 2153, 4, 1, 20.00, 0.00, 0),
(676, 2153, 24, 4, 72.00, 0.00, 0),
(677, 2154, 10, 1, 25.00, 0.00, 0),
(678, 2154, 31, 12, 24.00, 0.00, 0),
(679, 2154, 24, 1, 18.00, 0.00, 0),
(680, 2155, 25, 1, 12.00, 0.00, 0),
(681, 2155, 9, 2, 40.00, 0.00, 0),
(682, 2155, 6, 3, 45.00, 0.00, 0),
(683, 2155, 6, 2, 30.00, 0.00, 0),
(684, 2155, 20, 1, 40.00, 0.00, 0),
(685, 2155, 23, 1, 15.00, 0.00, 0),
(686, 2155, 2, 1, 12.00, 0.00, 0),
(687, 2155, 31, 21, 42.00, 0.00, 0),
(688, 2155, 25, 5, 60.00, 0.00, 0),
(689, 2155, 1, 3, 30.00, 0.00, 0),
(690, 2156, 22, 1, 35.00, 0.00, 0),
(691, 2156, 26, 3, 30.00, 0.00, 0),
(692, 2156, 23, 4, 60.00, 0.00, 0),
(693, 2157, 30, 2, 16.00, 0.00, 0),
(694, 2157, 11, 3, 36.00, 0.00, 0),
(695, 2157, 11, 5, 60.00, 0.00, 0),
(696, 2157, 25, 2, 24.00, 0.00, 0),
(697, 2157, 30, 5, 40.00, 0.00, 0),
(698, 2157, 29, 1, 28.00, 0.00, 0),
(699, 2157, 3, 5, 75.00, 0.00, 0),
(700, 2157, 5, 5, 60.00, 0.00, 0),
(701, 2158, 22, 2, 70.00, 0.00, 0),
(702, 2158, 31, 22, 44.00, 0.00, 0),
(703, 2158, 3, 1, 15.00, 0.00, 0),
(704, 2158, 25, 2, 24.00, 0.00, 0),
(705, 2158, 8, 5, 50.00, 0.00, 0),
(706, 2158, 11, 5, 60.00, 0.00, 0),
(707, 2158, 32, 4, 40.00, 0.00, 0),
(708, 2158, 23, 2, 30.00, 0.00, 0),
(709, 2158, 11, 4, 48.00, 0.00, 0),
(710, 2158, 18, 2, 24.00, 0.00, 0),
(711, 2158, 27, 5, 60.00, 0.00, 0),
(712, 2158, 20, 1, 40.00, 0.00, 0),
(713, 2159, 20, 1, 40.00, 0.00, 0),
(714, 2160, 12, 2, 40.00, 0.00, 0),
(715, 2160, 23, 1, 15.00, 0.00, 0),
(716, 2160, 25, 3, 36.00, 0.00, 0),
(717, 2161, 29, 1, 28.00, 0.00, 0),
(718, 2161, 20, 2, 80.00, 0.00, 0),
(719, 2161, 25, 2, 24.00, 0.00, 0),
(720, 2161, 31, 14, 28.00, 0.00, 0),
(721, 2161, 10, 1, 25.00, 0.00, 0),
(722, 2161, 11, 5, 60.00, 0.00, 0),
(723, 2161, 3, 3, 45.00, 0.00, 0),
(724, 2161, 11, 5, 60.00, 0.00, 0),
(725, 2161, 19, 2, 70.00, 0.00, 0),
(726, 2161, 23, 1, 15.00, 0.00, 0),
(727, 2161, 4, 2, 40.00, 0.00, 0),
(728, 2161, 19, 1, 35.00, 0.00, 0),
(729, 2162, 7, 5, 90.00, 0.00, 0),
(730, 2162, 2, 5, 60.00, 0.00, 0),
(731, 2162, 7, 5, 90.00, 0.00, 0),
(732, 2162, 23, 2, 30.00, 0.00, 0),
(733, 2162, 1, 5, 50.00, 0.00, 0),
(734, 2162, 32, 1, 10.00, 0.00, 0),
(735, 2162, 14, 1, 18.00, 0.00, 0),
(736, 2163, 8, 1, 10.00, 0.00, 0),
(737, 2163, 22, 1, 35.00, 0.00, 0),
(738, 2163, 25, 5, 60.00, 0.00, 0),
(739, 2163, 10, 2, 50.00, 0.00, 0),
(740, 2163, 17, 4, 60.00, 0.00, 0),
(741, 2163, 13, 1, 25.00, 0.00, 0),
(742, 2163, 30, 3, 24.00, 0.00, 0),
(743, 2163, 1, 2, 20.00, 0.00, 0),
(744, 2163, 27, 4, 48.00, 0.00, 0),
(745, 2163, 26, 1, 10.00, 0.00, 0),
(746, 2164, 30, 4, 32.00, 0.00, 0),
(747, 2164, 17, 3, 45.00, 0.00, 0),
(748, 2164, 17, 5, 75.00, 0.00, 0),
(749, 2164, 22, 2, 70.00, 0.00, 0),
(750, 2164, 4, 2, 40.00, 0.00, 0),
(751, 2164, 22, 1, 35.00, 0.00, 0),
(752, 2164, 5, 3, 36.00, 0.00, 0),
(753, 2164, 1, 3, 30.00, 0.00, 0),
(754, 2164, 2, 3, 36.00, 0.00, 0),
(755, 2164, 12, 2, 40.00, 0.00, 0),
(756, 2165, 10, 1, 25.00, 0.00, 0),
(757, 2165, 5, 5, 60.00, 0.00, 0),
(758, 2165, 11, 3, 36.00, 0.00, 0),
(759, 2165, 25, 1, 12.00, 0.00, 0),
(760, 2165, 6, 5, 75.00, 0.00, 0),
(761, 2165, 20, 1, 40.00, 0.00, 0),
(762, 2165, 31, 7, 14.00, 0.00, 0),
(763, 2165, 23, 3, 45.00, 0.00, 0),
(764, 2165, 9, 1, 20.00, 0.00, 0),
(765, 2166, 6, 2, 30.00, 0.00, 0),
(766, 2166, 29, 1, 28.00, 0.00, 0),
(767, 2166, 6, 4, 60.00, 0.00, 0),
(768, 2166, 8, 1, 10.00, 0.00, 0),
(769, 2166, 2, 1, 12.00, 0.00, 0),
(770, 2166, 27, 2, 24.00, 0.00, 0),
(771, 2166, 14, 5, 90.00, 0.00, 0),
(772, 2166, 21, 1, 20.00, 0.00, 0),
(773, 2166, 4, 2, 40.00, 0.00, 0),
(774, 2167, 7, 5, 90.00, 0.00, 0),
(775, 2167, 5, 4, 48.00, 0.00, 0),
(776, 2167, 10, 2, 50.00, 0.00, 0),
(777, 2167, 17, 1, 15.00, 0.00, 0),
(778, 2167, 1, 1, 10.00, 0.00, 0),
(779, 2167, 29, 1, 28.00, 0.00, 0),
(780, 2167, 2, 3, 36.00, 0.00, 0),
(781, 2168, 3, 4, 60.00, 0.00, 0),
(782, 2169, 29, 1, 28.00, 0.00, 0),
(783, 2169, 6, 3, 45.00, 0.00, 0),
(784, 2169, 10, 2, 50.00, 0.00, 0),
(785, 2169, 20, 1, 40.00, 0.00, 0),
(786, 2169, 19, 2, 70.00, 0.00, 0),
(787, 2169, 26, 1, 10.00, 0.00, 0),
(788, 2169, 9, 2, 40.00, 0.00, 0),
(789, 2170, 20, 1, 40.00, 0.00, 0),
(790, 2170, 8, 4, 40.00, 0.00, 0),
(791, 2171, 23, 3, 45.00, 0.00, 0),
(792, 2171, 7, 3, 54.00, 0.00, 0),
(793, 2171, 11, 2, 24.00, 0.00, 0),
(794, 2171, 26, 3, 30.00, 0.00, 0),
(795, 2171, 26, 3, 30.00, 0.00, 0),
(796, 2171, 2, 1, 12.00, 0.00, 0),
(797, 2171, 9, 1, 20.00, 0.00, 0),
(798, 2171, 29, 2, 56.00, 0.00, 0),
(799, 2171, 7, 4, 72.00, 0.00, 0),
(800, 2171, 19, 1, 35.00, 0.00, 0),
(801, 2172, 9, 2, 40.00, 0.00, 0),
(802, 2172, 2, 4, 48.00, 0.00, 0),
(803, 2172, 6, 2, 30.00, 0.00, 0),
(804, 2172, 18, 1, 12.00, 0.00, 0),
(805, 2173, 29, 2, 56.00, 0.00, 0),
(806, 2173, 20, 2, 80.00, 0.00, 0),
(807, 2173, 13, 1, 25.00, 0.00, 0),
(808, 2173, 29, 1, 28.00, 0.00, 0),
(809, 2173, 2, 5, 60.00, 0.00, 0),
(810, 2173, 21, 1, 20.00, 0.00, 0),
(811, 2173, 11, 4, 48.00, 0.00, 0),
(812, 2173, 20, 1, 40.00, 0.00, 0),
(813, 2173, 14, 2, 36.00, 0.00, 0),
(814, 2174, 17, 4, 60.00, 0.00, 0),
(815, 2174, 11, 5, 60.00, 0.00, 0),
(816, 2174, 7, 3, 54.00, 0.00, 0),
(817, 2174, 14, 3, 54.00, 0.00, 0),
(818, 2174, 24, 5, 90.00, 0.00, 0),
(819, 2174, 2, 4, 48.00, 0.00, 0),
(820, 2175, 10, 2, 50.00, 0.00, 0),
(821, 2175, 21, 2, 40.00, 0.00, 0),
(822, 2175, 24, 5, 90.00, 0.00, 1),
(823, 2175, 30, 4, 32.00, 0.00, 0),
(824, 2175, 1, 4, 40.00, 0.00, 0),
(825, 2175, 10, 2, 50.00, 0.00, 1),
(826, 2175, 11, 1, 12.00, 0.00, 0),
(827, 2176, 31, 19, 38.00, 0.00, 0),
(828, 2176, 9, 1, 20.00, 0.00, 0),
(829, 2176, 9, 2, 40.00, 0.00, 0),
(830, 2177, 25, 5, 60.00, 0.00, 0),
(831, 2177, 23, 3, 45.00, 0.00, 0),
(832, 2177, 3, 1, 15.00, 0.00, 0),
(833, 2177, 18, 3, 36.00, 0.00, 1),
(834, 2177, 4, 2, 40.00, 0.00, 0),
(835, 2177, 18, 4, 48.00, 0.00, 0),
(836, 2177, 9, 2, 40.00, 0.00, 0),
(837, 2177, 19, 2, 70.00, 0.00, 0),
(838, 2177, 6, 5, 75.00, 0.00, 0),
(839, 2177, 25, 5, 60.00, 0.00, 0),
(840, 2178, 27, 4, 48.00, 0.00, 0),
(841, 2178, 11, 5, 60.00, 0.00, 0),
(842, 2178, 21, 1, 20.00, 0.00, 0),
(843, 2178, 29, 2, 56.00, 0.00, 0),
(844, 2179, 14, 3, 54.00, 0.00, 0),
(845, 2179, 7, 2, 36.00, 0.00, 0),
(846, 2179, 30, 5, 40.00, 0.00, 0),
(847, 2179, 18, 3, 36.00, 0.00, 0),
(848, 2179, 31, 8, 16.00, 0.00, 0),
(849, 2179, 19, 2, 70.00, 0.00, 0),
(850, 2179, 11, 4, 48.00, 0.00, 0),
(851, 2179, 20, 2, 80.00, 0.00, 0),
(852, 2179, 25, 5, 60.00, 0.00, 0),
(853, 2180, 7, 2, 36.00, 0.00, 0),
(854, 2180, 1, 5, 50.00, 0.00, 0),
(855, 2180, 9, 2, 40.00, 0.00, 0),
(856, 2180, 31, 24, 48.00, 0.00, 0),
(857, 2180, 30, 3, 24.00, 0.00, 0),
(858, 2180, 24, 3, 54.00, 0.00, 0),
(859, 2180, 29, 2, 56.00, 0.00, 0),
(860, 2180, 9, 1, 20.00, 0.00, 0),
(861, 2180, 17, 1, 15.00, 0.00, 0),
(862, 2181, 31, 16, 32.00, 0.00, 0),
(863, 2181, 14, 4, 72.00, 0.00, 0),
(864, 2181, 29, 1, 28.00, 0.00, 0),
(865, 2181, 23, 2, 30.00, 0.00, 0),
(866, 2181, 23, 3, 45.00, 0.00, 0),
(867, 2182, 26, 1, 10.00, 0.00, 0),
(868, 2182, 3, 3, 45.00, 0.00, 0),
(869, 2182, 12, 2, 40.00, 0.00, 0),
(870, 2182, 31, 14, 28.00, 0.00, 0),
(871, 2182, 14, 4, 72.00, 0.00, 0),
(872, 2183, 30, 4, 32.00, 0.00, 0),
(873, 2183, 25, 1, 12.00, 0.00, 0),
(874, 2183, 5, 2, 24.00, 0.00, 0),
(875, 2183, 18, 1, 12.00, 0.00, 0),
(876, 2183, 7, 1, 18.00, 0.00, 0),
(877, 2183, 17, 3, 45.00, 0.00, 0),
(878, 2183, 27, 3, 36.00, 0.00, 0),
(879, 2183, 4, 1, 20.00, 0.00, 0),
(880, 2184, 5, 1, 12.00, 0.00, 0),
(881, 2185, 6, 4, 60.00, 0.00, 0),
(882, 2185, 26, 1, 10.00, 0.00, 0),
(883, 2185, 2, 4, 48.00, 0.00, 0),
(884, 2186, 4, 2, 40.00, 0.00, 0),
(885, 2186, 32, 1, 10.00, 0.00, 0),
(886, 2186, 4, 2, 40.00, 0.00, 0),
(887, 2187, 13, 1, 25.00, 0.00, 0),
(888, 2187, 31, 5, 10.00, 0.00, 0),
(889, 2187, 22, 2, 70.00, 0.00, 0),
(890, 2187, 1, 4, 40.00, 0.00, 0),
(891, 2187, 17, 3, 45.00, 0.00, 0),
(892, 2187, 21, 1, 20.00, 0.00, 0),
(893, 2187, 25, 5, 60.00, 0.00, 0),
(894, 2187, 3, 1, 15.00, 0.00, 0),
(895, 2187, 2, 3, 36.00, 0.00, 0),
(896, 2188, 32, 4, 40.00, 0.00, 0),
(897, 2188, 17, 1, 15.00, 0.00, 0),
(898, 2188, 14, 3, 54.00, 0.00, 0),
(899, 2189, 22, 2, 70.00, 0.00, 0),
(900, 2189, 23, 2, 30.00, 0.00, 0),
(901, 2190, 4, 1, 20.00, 0.00, 0),
(902, 2190, 13, 1, 25.00, 0.00, 0),
(903, 2190, 32, 2, 20.00, 0.00, 0),
(904, 2190, 7, 3, 54.00, 0.00, 0),
(905, 2190, 20, 1, 40.00, 0.00, 0),
(906, 2190, 19, 2, 70.00, 0.00, 0),
(907, 2190, 4, 1, 20.00, 0.00, 0),
(908, 2191, 23, 3, 45.00, 0.00, 0),
(909, 2191, 2, 1, 12.00, 0.00, 0),
(910, 2191, 17, 4, 60.00, 0.00, 0),
(911, 2191, 22, 1, 35.00, 0.00, 0),
(912, 2191, 19, 1, 35.00, 0.00, 0),
(913, 2191, 4, 2, 40.00, 0.00, 0),
(914, 2191, 18, 4, 48.00, 0.00, 0),
(915, 2192, 12, 1, 20.00, 0.00, 0),
(916, 2192, 1, 4, 40.00, 0.00, 0),
(917, 2192, 19, 1, 35.00, 0.00, 0),
(918, 2192, 4, 2, 40.00, 0.00, 0),
(919, 2193, 9, 2, 40.00, 0.00, 0),
(920, 2194, 22, 1, 35.00, 0.00, 0),
(921, 2194, 5, 1, 12.00, 0.00, 0),
(922, 2194, 30, 3, 24.00, 0.00, 0),
(923, 2194, 20, 2, 80.00, 0.00, 0),
(924, 2194, 20, 2, 80.00, 0.00, 0),
(925, 2194, 5, 1, 12.00, 0.00, 0),
(926, 2194, 14, 3, 54.00, 0.00, 0),
(927, 2195, 32, 3, 30.00, 0.00, 0),
(928, 2195, 4, 2, 40.00, 0.00, 0),
(929, 2195, 23, 4, 60.00, 0.00, 0),
(930, 2195, 29, 2, 56.00, 0.00, 0),
(931, 2196, 19, 2, 70.00, 0.00, 0),
(932, 2196, 13, 1, 25.00, 0.00, 0),
(933, 2196, 19, 2, 70.00, 0.00, 0),
(934, 2196, 3, 4, 60.00, 0.00, 0),
(935, 2197, 24, 3, 54.00, 0.00, 0),
(936, 2198, 23, 1, 15.00, 0.00, 0),
(937, 2199, 6, 5, 75.00, 0.00, 0),
(938, 2199, 23, 4, 60.00, 0.00, 0),
(939, 2199, 10, 2, 50.00, 0.00, 0),
(940, 2199, 30, 1, 8.00, 0.00, 0),
(941, 2199, 31, 7, 14.00, 0.00, 0),
(942, 2199, 14, 3, 54.00, 0.00, 0),
(943, 2200, 27, 5, 60.00, 0.00, 0),
(944, 2200, 1, 3, 30.00, 0.00, 0),
(945, 2200, 11, 3, 36.00, 0.00, 0),
(946, 2201, 22, 2, 70.00, 0.00, 0),
(947, 2201, 24, 1, 18.00, 0.00, 0),
(948, 2201, 5, 3, 36.00, 0.00, 0),
(949, 2201, 2, 4, 48.00, 0.00, 0),
(950, 2201, 21, 2, 40.00, 0.00, 0),
(951, 2201, 7, 3, 54.00, 0.00, 0),
(952, 2201, 22, 2, 70.00, 0.00, 0),
(953, 2202, 27, 5, 60.00, 0.00, 0),
(954, 2202, 19, 2, 70.00, 0.00, 0),
(955, 2202, 7, 3, 54.00, 0.00, 0),
(956, 2202, 17, 1, 15.00, 0.00, 0),
(957, 2202, 10, 2, 50.00, 0.00, 0),
(958, 2202, 26, 5, 50.00, 0.00, 0),
(959, 2202, 30, 1, 8.00, 0.00, 0),
(960, 2202, 22, 1, 35.00, 0.00, 0),
(961, 2202, 8, 3, 30.00, 0.00, 0),
(962, 2202, 7, 1, 18.00, 0.00, 0),
(963, 2203, 5, 5, 60.00, 0.00, 0),
(964, 2203, 23, 3, 45.00, 0.00, 0),
(965, 2203, 29, 2, 56.00, 0.00, 0),
(966, 2203, 12, 1, 20.00, 0.00, 0),
(967, 2203, 14, 2, 36.00, 0.00, 0),
(968, 2203, 22, 1, 35.00, 0.00, 0),
(969, 2203, 21, 1, 20.00, 0.00, 0),
(970, 2203, 11, 4, 48.00, 0.00, 0),
(971, 2203, 11, 3, 36.00, 0.00, 0),
(972, 2203, 3, 2, 30.00, 0.00, 0),
(973, 2203, 31, 20, 40.00, 0.00, 0),
(974, 2203, 12, 1, 20.00, 0.00, 0),
(975, 2204, 6, 3, 45.00, 0.00, 0),
(976, 2204, 22, 2, 70.00, 0.00, 0),
(977, 2204, 25, 2, 24.00, 0.00, 0),
(978, 2204, 25, 3, 36.00, 0.00, 0),
(979, 2204, 14, 5, 90.00, 0.00, 0),
(980, 2204, 23, 1, 15.00, 0.00, 0),
(981, 2204, 4, 1, 20.00, 0.00, 0),
(982, 2204, 12, 1, 20.00, 0.00, 0),
(983, 2204, 13, 2, 50.00, 0.00, 0),
(984, 2204, 2, 5, 60.00, 0.00, 0),
(985, 2204, 19, 1, 35.00, 0.00, 0),
(986, 2204, 8, 2, 20.00, 0.00, 0),
(987, 2205, 30, 1, 8.00, 0.00, 0),
(988, 2205, 19, 1, 35.00, 0.00, 0),
(989, 2206, 11, 3, 36.00, 0.00, 0),
(990, 2206, 1, 1, 10.00, 0.00, 0),
(991, 2206, 32, 5, 50.00, 0.00, 0),
(992, 2206, 13, 2, 50.00, 0.00, 0),
(993, 2207, 3, 1, 15.00, 0.00, 0),
(994, 2207, 9, 1, 20.00, 0.00, 0),
(995, 2207, 26, 4, 40.00, 0.00, 0),
(996, 2208, 25, 1, 12.00, 0.00, 0),
(997, 2208, 1, 2, 20.00, 0.00, 0),
(998, 2208, 3, 4, 60.00, 0.00, 0),
(999, 2209, 14, 4, 72.00, 0.00, 0),
(1000, 2209, 19, 1, 35.00, 0.00, 0),
(1001, 2209, 13, 2, 50.00, 0.00, 0),
(1002, 2209, 22, 1, 35.00, 0.00, 0),
(1003, 2209, 24, 1, 18.00, 0.00, 0),
(1004, 2209, 3, 2, 30.00, 0.00, 0),
(1005, 2209, 9, 1, 20.00, 0.00, 0),
(1006, 2209, 12, 2, 40.00, 0.00, 0),
(1007, 2209, 7, 4, 72.00, 0.00, 0),
(1008, 2210, 12, 1, 20.00, 0.00, 0),
(1009, 2210, 27, 2, 24.00, 0.00, 0),
(1010, 2210, 32, 4, 40.00, 0.00, 0),
(1011, 2210, 22, 1, 35.00, 0.00, 0),
(1012, 2210, 3, 4, 60.00, 0.00, 0),
(1013, 2210, 10, 1, 25.00, 0.00, 0),
(1014, 2210, 3, 4, 60.00, 0.00, 0),
(1015, 2210, 24, 3, 54.00, 0.00, 0),
(1016, 2211, 20, 1, 40.00, 0.00, 0),
(1017, 2211, 12, 1, 20.00, 0.00, 0),
(1018, 2211, 3, 3, 45.00, 0.00, 0),
(1019, 2211, 11, 1, 12.00, 0.00, 0),
(1020, 2211, 2, 2, 24.00, 0.00, 0),
(1021, 2211, 22, 1, 35.00, 0.00, 0),
(1022, 2212, 23, 3, 45.00, 0.00, 0),
(1023, 2213, 22, 2, 70.00, 0.00, 0),
(1024, 2213, 7, 2, 36.00, 0.00, 0),
(1025, 2213, 17, 5, 75.00, 0.00, 0),
(1026, 2213, 19, 2, 70.00, 0.00, 0),
(1027, 2214, 20, 2, 80.00, 0.00, 0),
(1028, 2214, 20, 1, 40.00, 0.00, 0),
(1029, 2214, 2, 3, 36.00, 0.00, 0),
(1030, 2214, 9, 1, 20.00, 0.00, 0),
(1031, 2214, 25, 3, 36.00, 0.00, 0),
(1032, 2214, 31, 15, 30.00, 0.00, 0),
(1033, 2214, 14, 5, 90.00, 0.00, 0),
(1034, 2215, 4, 2, 40.00, 0.00, 0),
(1035, 2215, 6, 5, 75.00, 0.00, 0),
(1036, 2215, 17, 3, 45.00, 0.00, 0),
(1037, 2215, 17, 3, 45.00, 0.00, 0),
(1038, 2215, 26, 1, 10.00, 0.00, 0),
(1039, 2215, 30, 2, 16.00, 0.00, 0),
(1040, 2215, 10, 1, 25.00, 0.00, 0),
(1041, 2216, 29, 1, 28.00, 0.00, 0),
(1042, 2216, 12, 1, 20.00, 0.00, 0),
(1043, 2216, 17, 2, 30.00, 0.00, 0),
(1044, 2216, 12, 2, 40.00, 0.00, 0),
(1045, 2216, 19, 1, 35.00, 0.00, 0),
(1046, 2216, 5, 2, 24.00, 0.00, 1),
(1047, 2216, 4, 1, 20.00, 0.00, 0),
(1048, 2216, 11, 5, 60.00, 0.00, 0),
(1049, 2216, 3, 2, 30.00, 0.00, 0),
(1050, 2216, 14, 5, 90.00, 0.00, 0),
(1051, 2216, 17, 2, 30.00, 0.00, 0),
(1052, 2216, 24, 5, 90.00, 0.00, 0),
(1053, 2217, 12, 1, 20.00, 0.00, 0),
(1054, 2218, 30, 3, 24.00, 0.00, 0),
(1055, 2218, 23, 3, 45.00, 0.00, 0),
(1056, 2218, 17, 1, 15.00, 0.00, 0),
(1057, 2218, 1, 2, 20.00, 0.00, 0),
(1058, 2218, 1, 5, 50.00, 0.00, 0),
(1059, 2218, 19, 1, 35.00, 0.00, 0),
(1060, 2218, 11, 2, 24.00, 0.00, 0),
(1061, 2218, 31, 16, 32.00, 0.00, 0),
(1062, 2218, 12, 2, 40.00, 0.00, 0),
(1063, 2218, 26, 2, 20.00, 0.00, 0),
(1064, 2218, 5, 5, 60.00, 0.00, 0),
(1065, 2219, 7, 1, 18.00, 0.00, 0),
(1066, 2219, 10, 1, 25.00, 0.00, 0),
(1067, 2219, 23, 4, 60.00, 0.00, 0),
(1068, 2219, 23, 2, 30.00, 0.00, 0),
(1069, 2219, 7, 1, 18.00, 0.00, 0),
(1070, 2220, 22, 2, 70.00, 0.00, 0),
(1071, 2220, 18, 4, 48.00, 0.00, 0),
(1072, 2220, 26, 1, 10.00, 0.00, 0),
(1073, 2220, 30, 4, 32.00, 0.00, 0),
(1074, 2220, 8, 2, 20.00, 0.00, 0),
(1075, 2220, 27, 4, 48.00, 0.00, 0),
(1076, 2220, 6, 2, 30.00, 0.00, 0),
(1077, 2220, 23, 3, 45.00, 0.00, 0),
(1078, 2220, 24, 1, 18.00, 0.00, 0),
(1079, 2221, 27, 5, 60.00, 0.00, 0),
(1080, 2221, 32, 1, 10.00, 0.00, 0),
(1081, 2221, 31, 5, 10.00, 0.00, 0),
(1082, 2221, 1, 4, 40.00, 0.00, 0),
(1083, 2221, 20, 2, 80.00, 0.00, 0),
(1084, 2221, 14, 2, 36.00, 0.00, 0),
(1085, 2221, 8, 5, 50.00, 0.00, 0),
(1086, 2221, 31, 11, 22.00, 0.00, 0),
(1087, 2221, 30, 1, 8.00, 0.00, 0),
(1088, 2221, 3, 3, 45.00, 0.00, 0),
(1089, 2222, 1, 1, 10.00, 0.00, 0),
(1090, 2222, 9, 1, 20.00, 0.00, 0),
(1091, 2222, 4, 2, 40.00, 0.00, 0),
(1092, 2222, 19, 2, 70.00, 0.00, 0),
(1093, 2222, 11, 4, 48.00, 0.00, 0),
(1094, 2222, 6, 4, 60.00, 0.00, 0),
(1095, 2222, 14, 1, 18.00, 0.00, 0),
(1096, 2223, 5, 1, 12.00, 0.00, 0),
(1097, 2223, 7, 3, 54.00, 0.00, 0),
(1098, 2223, 32, 4, 40.00, 0.00, 0),
(1099, 2223, 8, 3, 30.00, 0.00, 1),
(1100, 2223, 11, 2, 24.00, 0.00, 0),
(1101, 2223, 5, 2, 24.00, 0.00, 0),
(1102, 2223, 20, 2, 80.00, 0.00, 0),
(1103, 2224, 13, 1, 25.00, 0.00, 0),
(1104, 2224, 8, 4, 40.00, 0.00, 0),
(1105, 2224, 19, 1, 35.00, 0.00, 0),
(1106, 2224, 18, 4, 48.00, 0.00, 0),
(1107, 2224, 29, 2, 56.00, 0.00, 0),
(1108, 2224, 31, 20, 40.00, 0.00, 0),
(1109, 2225, 3, 1, 15.00, 0.00, 0),
(1110, 2225, 23, 5, 75.00, 0.00, 0),
(1111, 2225, 13, 2, 50.00, 0.00, 0),
(1112, 2225, 21, 1, 20.00, 0.00, 0),
(1113, 2225, 18, 4, 48.00, 0.00, 0),
(1114, 2225, 11, 2, 24.00, 0.00, 0),
(1115, 2225, 4, 1, 20.00, 0.00, 0),
(1116, 2226, 31, 5, 10.00, 0.00, 0),
(1117, 2226, 6, 2, 30.00, 0.00, 0),
(1118, 2226, 24, 3, 54.00, 0.00, 0),
(1119, 2226, 18, 3, 36.00, 0.00, 0),
(1120, 2226, 10, 1, 25.00, 0.00, 0),
(1121, 2226, 2, 4, 48.00, 0.00, 0),
(1122, 2226, 22, 2, 70.00, 0.00, 0),
(1123, 2226, 11, 5, 60.00, 0.00, 0),
(1124, 2226, 17, 5, 75.00, 0.00, 0),
(1125, 2226, 4, 1, 20.00, 0.00, 0),
(1126, 2226, 26, 1, 10.00, 0.00, 0),
(1127, 2226, 7, 3, 54.00, 0.00, 0),
(1128, 2227, 20, 1, 40.00, 0.00, 0),
(1129, 2227, 19, 1, 35.00, 0.00, 0),
(1130, 2227, 25, 3, 36.00, 0.00, 0),
(1131, 2228, 9, 1, 20.00, 0.00, 0),
(1132, 2228, 24, 3, 54.00, 0.00, 0),
(1133, 2228, 25, 4, 48.00, 0.00, 0),
(1134, 2228, 10, 2, 50.00, 0.00, 0),
(1135, 2228, 9, 2, 40.00, 0.00, 0),
(1136, 2228, 24, 1, 18.00, 0.00, 0),
(1137, 2228, 17, 3, 45.00, 0.00, 0),
(1138, 2228, 24, 1, 18.00, 0.00, 0),
(1139, 2229, 24, 2, 36.00, 0.00, 0),
(1140, 2229, 31, 16, 32.00, 0.00, 0),
(1141, 2230, 13, 2, 50.00, 0.00, 0),
(1142, 2230, 6, 3, 45.00, 0.00, 0),
(1143, 2230, 10, 1, 25.00, 0.00, 0),
(1144, 2230, 9, 2, 40.00, 0.00, 0),
(1145, 2230, 20, 2, 80.00, 0.00, 0),
(1146, 2230, 19, 1, 35.00, 0.00, 0),
(1147, 2230, 9, 1, 20.00, 0.00, 0),
(1148, 2230, 14, 3, 54.00, 0.00, 1),
(1149, 2230, 27, 3, 36.00, 0.00, 0),
(1150, 2230, 13, 1, 25.00, 0.00, 0),
(1151, 2230, 3, 5, 75.00, 0.00, 0),
(1152, 2230, 27, 2, 24.00, 0.00, 0),
(1153, 2231, 6, 1, 15.00, 0.00, 0),
(1154, 2231, 3, 2, 30.00, 0.00, 0),
(1155, 2231, 26, 1, 10.00, 0.00, 0),
(1156, 2231, 31, 15, 30.00, 0.00, 0),
(1157, 2231, 19, 2, 70.00, 0.00, 0),
(1158, 2232, 25, 2, 24.00, 0.00, 1),
(1159, 2232, 23, 2, 30.00, 0.00, 0),
(1160, 2232, 24, 2, 36.00, 0.00, 0),
(1161, 2232, 6, 1, 15.00, 0.00, 0),
(1162, 2233, 26, 3, 30.00, 0.00, 0),
(1163, 2233, 10, 2, 50.00, 0.00, 0),
(1164, 2233, 1, 4, 40.00, 0.00, 0),
(1165, 2233, 17, 5, 75.00, 0.00, 0),
(1166, 2233, 21, 2, 40.00, 0.00, 0),
(1167, 2233, 25, 2, 24.00, 0.00, 0),
(1168, 2234, 31, 9, 18.00, 0.00, 0),
(1169, 2234, 20, 2, 80.00, 0.00, 0),
(1170, 2234, 11, 4, 48.00, 0.00, 0),
(1171, 2234, 8, 1, 10.00, 0.00, 0),
(1172, 2234, 3, 5, 75.00, 0.00, 0),
(1173, 2234, 9, 1, 20.00, 0.00, 0),
(1174, 2234, 19, 1, 35.00, 0.00, 0),
(1175, 2234, 13, 1, 25.00, 0.00, 0),
(1176, 2234, 5, 5, 60.00, 0.00, 0),
(1177, 2234, 29, 2, 56.00, 0.00, 0),
(1178, 2235, 30, 1, 8.00, 0.00, 0),
(1179, 2236, 10, 2, 50.00, 0.00, 0),
(1180, 2236, 9, 2, 40.00, 0.00, 0),
(1181, 2236, 10, 2, 50.00, 0.00, 0),
(1182, 2236, 26, 2, 20.00, 0.00, 0),
(1183, 2236, 12, 2, 40.00, 0.00, 0),
(1184, 2236, 17, 5, 75.00, 0.00, 0),
(1185, 2236, 23, 4, 60.00, 0.00, 0),
(1186, 2237, 12, 2, 40.00, 0.00, 0),
(1187, 2237, 25, 2, 24.00, 0.00, 0),
(1188, 2237, 20, 2, 80.00, 0.00, 0),
(1189, 2237, 22, 2, 70.00, 0.00, 0),
(1190, 2237, 14, 1, 18.00, 0.00, 0),
(1191, 2238, 29, 2, 56.00, 0.00, 0),
(1192, 2238, 27, 5, 60.00, 0.00, 0),
(1193, 2238, 4, 1, 20.00, 0.00, 0),
(1194, 2238, 24, 2, 36.00, 0.00, 0),
(1195, 2238, 12, 1, 20.00, 0.00, 0),
(1196, 2238, 24, 3, 54.00, 0.00, 0),
(1197, 2238, 7, 5, 90.00, 0.00, 0),
(1198, 2238, 10, 1, 25.00, 0.00, 0),
(1199, 2238, 8, 3, 30.00, 0.00, 0),
(1200, 2239, 17, 1, 15.00, 0.00, 0),
(1201, 2239, 12, 2, 40.00, 0.00, 0),
(1202, 2239, 22, 2, 70.00, 0.00, 0),
(1203, 2239, 7, 2, 36.00, 0.00, 0),
(1204, 2239, 1, 5, 50.00, 0.00, 0),
(1205, 2239, 5, 5, 60.00, 0.00, 0),
(1206, 2239, 11, 3, 36.00, 0.00, 0),
(1207, 2239, 4, 1, 20.00, 0.00, 0),
(1208, 2239, 31, 10, 20.00, 0.00, 0),
(1209, 2239, 30, 4, 32.00, 0.00, 0),
(1210, 2240, 3, 2, 30.00, 0.00, 0),
(1211, 2240, 31, 22, 44.00, 0.00, 0),
(1212, 2240, 23, 2, 30.00, 0.00, 0),
(1213, 2240, 11, 3, 36.00, 0.00, 0),
(1214, 2241, 29, 1, 28.00, 0.00, 0),
(1215, 2241, 19, 1, 35.00, 0.00, 0),
(1216, 2241, 11, 2, 24.00, 0.00, 0),
(1217, 2241, 17, 2, 30.00, 0.00, 0),
(1218, 2241, 32, 4, 40.00, 0.00, 0),
(1219, 2241, 14, 5, 90.00, 0.00, 0),
(1220, 2241, 12, 1, 20.00, 0.00, 0),
(1221, 2241, 29, 2, 56.00, 0.00, 0),
(1222, 2241, 17, 4, 60.00, 0.00, 0),
(1223, 2241, 32, 5, 50.00, 0.00, 0),
(1224, 2241, 12, 1, 20.00, 0.00, 0),
(1225, 2242, 6, 4, 60.00, 0.00, 0),
(1226, 2242, 24, 1, 18.00, 0.00, 0),
(1227, 2242, 4, 1, 20.00, 0.00, 0),
(1228, 2242, 4, 2, 40.00, 0.00, 0),
(1229, 2242, 27, 2, 24.00, 0.00, 0),
(1230, 2242, 31, 23, 46.00, 0.00, 0),
(1231, 2242, 19, 1, 35.00, 0.00, 0),
(1232, 2242, 2, 4, 48.00, 0.00, 0),
(1233, 2243, 32, 2, 20.00, 0.00, 0),
(1234, 2243, 20, 2, 80.00, 0.00, 0),
(1235, 2244, 5, 4, 48.00, 0.00, 0),
(1236, 2244, 1, 3, 30.00, 0.00, 0),
(1237, 2244, 14, 1, 18.00, 0.00, 0),
(1238, 2244, 32, 2, 20.00, 0.00, 0),
(1239, 2244, 30, 1, 8.00, 0.00, 0),
(1240, 2245, 2, 3, 36.00, 0.00, 0),
(1241, 2245, 14, 3, 54.00, 0.00, 0),
(1242, 2246, 9, 2, 40.00, 0.00, 0),
(1243, 2246, 25, 1, 12.00, 0.00, 0),
(1244, 2246, 13, 1, 25.00, 0.00, 0),
(1245, 2246, 6, 2, 30.00, 0.00, 0),
(1246, 2246, 11, 3, 36.00, 0.00, 0),
(1247, 2246, 9, 1, 20.00, 0.00, 0),
(1248, 2246, 18, 3, 36.00, 0.00, 0),
(1249, 2247, 17, 3, 45.00, 0.00, 0),
(1250, 2247, 4, 1, 20.00, 0.00, 0),
(1251, 2247, 32, 3, 30.00, 0.00, 0),
(1252, 2247, 24, 1, 18.00, 0.00, 0),
(1253, 2247, 8, 2, 20.00, 0.00, 0),
(1254, 2247, 12, 1, 20.00, 0.00, 0),
(1255, 2247, 10, 1, 25.00, 0.00, 0),
(1256, 2247, 12, 2, 40.00, 0.00, 0),
(1257, 2247, 7, 1, 18.00, 0.00, 0),
(1258, 2247, 24, 1, 18.00, 0.00, 0),
(1259, 2247, 32, 3, 30.00, 0.00, 0),
(1260, 2247, 12, 1, 20.00, 0.00, 0),
(1261, 2248, 17, 2, 30.00, 0.00, 0),
(1262, 2248, 6, 1, 15.00, 0.00, 0),
(1263, 2248, 24, 1, 18.00, 0.00, 0),
(1264, 2248, 7, 5, 90.00, 0.00, 0),
(1265, 2248, 25, 5, 60.00, 0.00, 0),
(1266, 2248, 23, 1, 15.00, 0.00, 0),
(1267, 2248, 25, 3, 36.00, 0.00, 0),
(1268, 2249, 1, 1, 10.00, 0.00, 0),
(1269, 2250, 11, 4, 48.00, 0.00, 0),
(1270, 2250, 10, 2, 50.00, 0.00, 0),
(1271, 2250, 6, 5, 75.00, 0.00, 0),
(1272, 2250, 9, 2, 40.00, 0.00, 0),
(1273, 2250, 19, 1, 35.00, 0.00, 0),
(1274, 2250, 18, 2, 24.00, 0.00, 0),
(1275, 2251, 27, 1, 12.00, 0.00, 0),
(1276, 2251, 31, 8, 16.00, 0.00, 0),
(1277, 2251, 9, 1, 20.00, 0.00, 0),
(1278, 2251, 19, 1, 35.00, 0.00, 0),
(1279, 2251, 27, 4, 48.00, 0.00, 0),
(1280, 2251, 25, 5, 60.00, 0.00, 0),
(1281, 2251, 22, 2, 70.00, 0.00, 0),
(1282, 2251, 8, 2, 20.00, 0.00, 0),
(1283, 2251, 2, 4, 48.00, 0.00, 0),
(1284, 2252, 31, 16, 32.00, 0.00, 0),
(1285, 2252, 27, 4, 48.00, 0.00, 0),
(1286, 2252, 12, 1, 20.00, 0.00, 0),
(1287, 2252, 1, 1, 10.00, 0.00, 0),
(1288, 2252, 1, 2, 20.00, 0.00, 0),
(1289, 2252, 25, 3, 36.00, 0.00, 0),
(1290, 2252, 9, 1, 20.00, 0.00, 0),
(1291, 2252, 6, 5, 75.00, 0.00, 0),
(1292, 2253, 31, 18, 36.00, 0.00, 0),
(1293, 2253, 29, 1, 28.00, 0.00, 0),
(1294, 2253, 10, 1, 25.00, 0.00, 0),
(1295, 2253, 30, 4, 32.00, 0.00, 0),
(1296, 2253, 11, 5, 60.00, 0.00, 0),
(1297, 2254, 13, 1, 25.00, 0.00, 0),
(1298, 2254, 5, 2, 24.00, 0.00, 0),
(1299, 2254, 24, 4, 72.00, 0.00, 0),
(1300, 2255, 14, 2, 36.00, 0.00, 0),
(1301, 2255, 1, 3, 30.00, 0.00, 0),
(1302, 2255, 30, 1, 8.00, 0.00, 0),
(1303, 2255, 4, 2, 40.00, 0.00, 0),
(1304, 2255, 2, 3, 36.00, 0.00, 0),
(1305, 2255, 8, 3, 30.00, 0.00, 0),
(1306, 2255, 29, 2, 56.00, 0.00, 0),
(1307, 2255, 12, 2, 40.00, 0.00, 0),
(1308, 2256, 14, 5, 90.00, 0.00, 0),
(1309, 2256, 24, 2, 36.00, 0.00, 0),
(1310, 2256, 11, 4, 48.00, 0.00, 0),
(1311, 2256, 10, 2, 50.00, 0.00, 0),
(1312, 2256, 29, 1, 28.00, 0.00, 0),
(1313, 2256, 2, 3, 36.00, 0.00, 0),
(1314, 2256, 27, 1, 12.00, 0.00, 0),
(1315, 2257, 25, 4, 48.00, 0.00, 0),
(1316, 2257, 29, 2, 56.00, 0.00, 0),
(1317, 2257, 21, 2, 40.00, 0.00, 0),
(1318, 2257, 2, 2, 24.00, 0.00, 0),
(1319, 2257, 7, 3, 54.00, 0.00, 0),
(1320, 2257, 27, 2, 24.00, 0.00, 0),
(1321, 2257, 23, 1, 15.00, 0.00, 0),
(1322, 2257, 20, 2, 80.00, 0.00, 0),
(1323, 2257, 22, 1, 35.00, 0.00, 0),
(1324, 2258, 17, 1, 15.00, 0.00, 0),
(1325, 2258, 1, 5, 50.00, 0.00, 0),
(1326, 2258, 18, 4, 48.00, 0.00, 0),
(1327, 2258, 10, 2, 50.00, 0.00, 0),
(1328, 2258, 11, 3, 36.00, 0.00, 0),
(1329, 2259, 29, 2, 56.00, 0.00, 0),
(1330, 2259, 3, 2, 30.00, 0.00, 0),
(1331, 2259, 29, 1, 28.00, 0.00, 0),
(1332, 2259, 14, 5, 90.00, 0.00, 0),
(1333, 2259, 17, 2, 30.00, 0.00, 0),
(1334, 2259, 32, 4, 40.00, 0.00, 0),
(1335, 2259, 31, 17, 34.00, 0.00, 0),
(1336, 2259, 31, 10, 20.00, 0.00, 0),
(1337, 2260, 13, 2, 50.00, 0.00, 0),
(1338, 2260, 27, 4, 48.00, 0.00, 0),
(1339, 2260, 22, 1, 35.00, 0.00, 0),
(1340, 2260, 3, 2, 30.00, 0.00, 0),
(1341, 2260, 3, 4, 60.00, 0.00, 0),
(1342, 2260, 20, 2, 80.00, 0.00, 0),
(1343, 2260, 7, 2, 36.00, 0.00, 0),
(1344, 2260, 24, 4, 72.00, 0.00, 0),
(1345, 2260, 27, 1, 12.00, 0.00, 0),
(1346, 2260, 24, 1, 18.00, 0.00, 0),
(1347, 2260, 4, 2, 40.00, 0.00, 0),
(1348, 2260, 13, 1, 25.00, 0.00, 0),
(1349, 2261, 31, 23, 46.00, 0.00, 0),
(1350, 2261, 24, 3, 54.00, 0.00, 0),
(1351, 2261, 22, 1, 35.00, 0.00, 0),
(1352, 2261, 4, 1, 20.00, 0.00, 0),
(1353, 2261, 30, 4, 32.00, 0.00, 0),
(1354, 2261, 6, 5, 75.00, 0.00, 0),
(1355, 2261, 8, 1, 10.00, 0.00, 0),
(1356, 2262, 1, 3, 30.00, 0.00, 0),
(1357, 2262, 29, 2, 56.00, 0.00, 0),
(1358, 2262, 5, 4, 48.00, 0.00, 0),
(1359, 2263, 4, 2, 40.00, 0.00, 0),
(1360, 2263, 32, 1, 10.00, 0.00, 0),
(1361, 2263, 25, 4, 48.00, 0.00, 0),
(1362, 2263, 21, 2, 40.00, 0.00, 0),
(1363, 2263, 20, 1, 40.00, 0.00, 0),
(1364, 2263, 12, 2, 40.00, 0.00, 0),
(1365, 2263, 5, 3, 36.00, 0.00, 0),
(1366, 2263, 23, 1, 15.00, 0.00, 0),
(1367, 2263, 24, 5, 90.00, 0.00, 0),
(1368, 2264, 17, 3, 45.00, 0.00, 0),
(1369, 2264, 21, 2, 40.00, 0.00, 0),
(1370, 2264, 19, 2, 70.00, 0.00, 0),
(1371, 2264, 25, 1, 12.00, 0.00, 0),
(1372, 2264, 25, 3, 36.00, 0.00, 0),
(1373, 2264, 2, 1, 12.00, 0.00, 0),
(1374, 2264, 12, 1, 20.00, 0.00, 0),
(1375, 2264, 24, 4, 72.00, 0.00, 0),
(1376, 2264, 21, 1, 20.00, 0.00, 0),
(1377, 2264, 30, 5, 40.00, 0.00, 0),
(1378, 2265, 21, 2, 40.00, 0.00, 0),
(1379, 2265, 20, 2, 80.00, 0.00, 0),
(1380, 2265, 31, 12, 24.00, 0.00, 0),
(1381, 2265, 22, 2, 70.00, 0.00, 0),
(1382, 2265, 21, 2, 40.00, 0.00, 0),
(1383, 2265, 27, 3, 36.00, 0.00, 0),
(1384, 2265, 10, 2, 50.00, 0.00, 0),
(1385, 2265, 32, 2, 20.00, 0.00, 0),
(1386, 2265, 4, 2, 40.00, 0.00, 0),
(1387, 2265, 20, 2, 80.00, 0.00, 0),
(1388, 2265, 13, 2, 50.00, 0.00, 0),
(1389, 2265, 21, 2, 40.00, 0.00, 0),
(1390, 2266, 23, 2, 30.00, 0.00, 0),
(1391, 2266, 20, 2, 80.00, 0.00, 0),
(1392, 2266, 30, 3, 24.00, 0.00, 0),
(1393, 2266, 21, 2, 40.00, 0.00, 0),
(1394, 2266, 26, 3, 30.00, 0.00, 0),
(1395, 2266, 21, 1, 20.00, 0.00, 0),
(1396, 2266, 8, 4, 40.00, 0.00, 0),
(1397, 2266, 30, 3, 24.00, 0.00, 0),
(1398, 2266, 14, 5, 90.00, 0.00, 0),
(1399, 2267, 9, 2, 40.00, 0.00, 0),
(1400, 2268, 11, 4, 48.00, 0.00, 0),
(1401, 2269, 7, 1, 18.00, 0.00, 0),
(1402, 2269, 12, 2, 40.00, 0.00, 0),
(1403, 2269, 21, 2, 40.00, 0.00, 0),
(1404, 2269, 22, 1, 35.00, 0.00, 0),
(1405, 2269, 25, 3, 36.00, 0.00, 0),
(1406, 2269, 26, 2, 20.00, 0.00, 0),
(1407, 2269, 17, 1, 15.00, 0.00, 0),
(1408, 2269, 10, 1, 25.00, 0.00, 0),
(1409, 2269, 9, 2, 40.00, 0.00, 0),
(1410, 2270, 23, 4, 60.00, 0.00, 0),
(1411, 2270, 18, 2, 24.00, 0.00, 0),
(1412, 2270, 24, 4, 72.00, 0.00, 0),
(1413, 2270, 30, 2, 16.00, 0.00, 0),
(1414, 2270, 18, 4, 48.00, 0.00, 0),
(1415, 2271, 23, 1, 15.00, 0.00, 0),
(1416, 2271, 4, 1, 20.00, 0.00, 0),
(1417, 2271, 3, 4, 60.00, 0.00, 0),
(1418, 2272, 23, 4, 60.00, 0.00, 0),
(1419, 2272, 27, 5, 60.00, 0.00, 0),
(1420, 2272, 6, 5, 75.00, 0.00, 0),
(1421, 2272, 10, 2, 50.00, 0.00, 0),
(1422, 2272, 29, 2, 56.00, 0.00, 0),
(1423, 2273, 5, 4, 48.00, 0.00, 0),
(1424, 2273, 5, 4, 48.00, 0.00, 0),
(1425, 2273, 23, 3, 45.00, 0.00, 0),
(1426, 2273, 10, 1, 25.00, 0.00, 0),
(1427, 2273, 5, 4, 48.00, 0.00, 0),
(1428, 2273, 32, 3, 30.00, 0.00, 0),
(1429, 2273, 13, 1, 25.00, 0.00, 0),
(1430, 2274, 13, 1, 25.00, 0.00, 0),
(1431, 2274, 11, 3, 36.00, 0.00, 0),
(1432, 2275, 14, 3, 54.00, 0.00, 0),
(1433, 2275, 23, 1, 15.00, 0.00, 0),
(1434, 2275, 24, 5, 90.00, 0.00, 0),
(1435, 2275, 29, 1, 28.00, 0.00, 0),
(1436, 2275, 19, 2, 70.00, 0.00, 0),
(1437, 2276, 31, 11, 22.00, 0.00, 0),
(1438, 2276, 17, 4, 60.00, 0.00, 0),
(1439, 2276, 20, 2, 80.00, 0.00, 0),
(1440, 2276, 25, 2, 24.00, 0.00, 0),
(1441, 2276, 13, 1, 25.00, 0.00, 0),
(1442, 2276, 13, 1, 25.00, 0.00, 0),
(1443, 2276, 5, 2, 24.00, 0.00, 0),
(1444, 2276, 10, 2, 50.00, 0.00, 0),
(1445, 2277, 26, 5, 50.00, 0.00, 0),
(1446, 2278, 7, 5, 90.00, 0.00, 0),
(1447, 2278, 12, 1, 20.00, 0.00, 0),
(1448, 2279, 32, 5, 50.00, 0.00, 0),
(1449, 2279, 1, 4, 40.00, 0.00, 0),
(1450, 2279, 21, 2, 40.00, 0.00, 0),
(1451, 2279, 18, 3, 36.00, 0.00, 0),
(1452, 2280, 18, 5, 60.00, 0.00, 0),
(1453, 2280, 4, 2, 40.00, 0.00, 0),
(1454, 2280, 12, 1, 20.00, 0.00, 0),
(1455, 2280, 11, 3, 36.00, 0.00, 0),
(1456, 2281, 10, 2, 50.00, 0.00, 0),
(1457, 2281, 26, 4, 40.00, 0.00, 0),
(1458, 2281, 20, 1, 40.00, 0.00, 0),
(1459, 2281, 1, 4, 40.00, 0.00, 0),
(1460, 2281, 17, 1, 15.00, 0.00, 0),
(1461, 2281, 10, 2, 50.00, 0.00, 0),
(1462, 2281, 31, 23, 46.00, 0.00, 0),
(1463, 2281, 2, 5, 60.00, 0.00, 0),
(1464, 2281, 7, 3, 54.00, 0.00, 0),
(1465, 2282, 31, 21, 42.00, 0.00, 0),
(1466, 2282, 17, 5, 75.00, 0.00, 0),
(1467, 2282, 14, 1, 18.00, 0.00, 0),
(1468, 2282, 10, 1, 25.00, 0.00, 0);
INSERT INTO `sales` (`sale_id`, `order_id`, `product_id`, `qty_sold`, `total_price`, `discount_percent`, `qty_returned`) VALUES
(1469, 2282, 2, 1, 12.00, 0.00, 0),
(1470, 2282, 25, 3, 36.00, 0.00, 0),
(1471, 2282, 5, 1, 12.00, 0.00, 0),
(1472, 2283, 1, 5, 50.00, 0.00, 0),
(1473, 2283, 27, 1, 12.00, 0.00, 0),
(1474, 2283, 29, 2, 56.00, 0.00, 0),
(1475, 2283, 21, 2, 40.00, 0.00, 0),
(1476, 2283, 14, 4, 72.00, 0.00, 0),
(1477, 2283, 6, 5, 75.00, 0.00, 0),
(1478, 2283, 32, 3, 30.00, 0.00, 0),
(1479, 2283, 8, 1, 10.00, 0.00, 0),
(1480, 2284, 32, 2, 20.00, 0.00, 0),
(1481, 2284, 1, 4, 40.00, 0.00, 0),
(1482, 2284, 20, 1, 40.00, 0.00, 0),
(1483, 2285, 31, 16, 32.00, 0.00, 0),
(1484, 2285, 27, 2, 24.00, 0.00, 0),
(1485, 2285, 2, 1, 12.00, 0.00, 0),
(1486, 2285, 27, 4, 48.00, 0.00, 0),
(1487, 2285, 3, 5, 75.00, 0.00, 0),
(1488, 2285, 5, 2, 24.00, 0.00, 0),
(1489, 2285, 3, 3, 45.00, 0.00, 0),
(1490, 2286, 2, 1, 12.00, 0.00, 0),
(1491, 2286, 2, 2, 24.00, 0.00, 0),
(1492, 2286, 32, 5, 50.00, 0.00, 0),
(1493, 2286, 31, 10, 20.00, 0.00, 0),
(1494, 2286, 1, 1, 10.00, 0.00, 0),
(1495, 2286, 21, 2, 40.00, 0.00, 0),
(1496, 2286, 7, 4, 72.00, 0.00, 0),
(1497, 2286, 18, 1, 12.00, 0.00, 0),
(1498, 2287, 27, 4, 48.00, 0.00, 0),
(1499, 2287, 14, 5, 90.00, 0.00, 0),
(1500, 2287, 29, 1, 28.00, 0.00, 0),
(1501, 2288, 24, 3, 54.00, 0.00, 0),
(1502, 2288, 19, 1, 35.00, 0.00, 0),
(1503, 2289, 27, 2, 24.00, 0.00, 0),
(1504, 2289, 12, 2, 40.00, 0.00, 0),
(1505, 2289, 19, 1, 35.00, 0.00, 0),
(1506, 2289, 5, 4, 48.00, 0.00, 0),
(1507, 2289, 30, 4, 32.00, 0.00, 0),
(1508, 2289, 10, 2, 50.00, 0.00, 0),
(1509, 2289, 7, 5, 90.00, 0.00, 0),
(1510, 2289, 8, 5, 50.00, 0.00, 0),
(1511, 2289, 30, 2, 16.00, 0.00, 0),
(1512, 2290, 30, 5, 40.00, 0.00, 0),
(1513, 2290, 17, 1, 15.00, 0.00, 0),
(1514, 2290, 21, 2, 40.00, 0.00, 0),
(1515, 2291, 5, 2, 24.00, 0.00, 0),
(1516, 2291, 18, 2, 24.00, 0.00, 0),
(1517, 2291, 22, 2, 70.00, 0.00, 0),
(1518, 2291, 18, 4, 48.00, 0.00, 0),
(1519, 2291, 13, 1, 25.00, 0.00, 0),
(1520, 2291, 13, 2, 50.00, 0.00, 0),
(1521, 2292, 23, 4, 60.00, 0.00, 0),
(1522, 2292, 21, 1, 20.00, 0.00, 0),
(1523, 2292, 18, 2, 24.00, 0.00, 0),
(1524, 2292, 20, 1, 40.00, 0.00, 0),
(1525, 2292, 4, 1, 20.00, 0.00, 0),
(1526, 2293, 2, 4, 48.00, 0.00, 0),
(1527, 2293, 19, 2, 70.00, 0.00, 0),
(1528, 2293, 11, 1, 12.00, 0.00, 0),
(1529, 2293, 3, 5, 75.00, 0.00, 0),
(1530, 2293, 17, 4, 60.00, 0.00, 0),
(1531, 2293, 19, 2, 70.00, 0.00, 0),
(1532, 2293, 20, 1, 40.00, 0.00, 0),
(1533, 2293, 4, 2, 40.00, 0.00, 0),
(1534, 2293, 11, 2, 24.00, 0.00, 0),
(1535, 2293, 30, 4, 32.00, 0.00, 0),
(1536, 2293, 17, 4, 60.00, 0.00, 0),
(1537, 2294, 19, 2, 70.00, 0.00, 0),
(1538, 2294, 9, 1, 20.00, 0.00, 0),
(1539, 2294, 26, 2, 20.00, 0.00, 0),
(1540, 2295, 25, 3, 36.00, 0.00, 0),
(1541, 2295, 29, 1, 28.00, 0.00, 0),
(1542, 2296, 19, 2, 70.00, 0.00, 0),
(1543, 2296, 22, 2, 70.00, 0.00, 0),
(1544, 2296, 26, 5, 50.00, 0.00, 0),
(1545, 2297, 20, 1, 40.00, 0.00, 0),
(1546, 2297, 11, 4, 48.00, 0.00, 0),
(1547, 2298, 21, 2, 40.00, 0.00, 0),
(1548, 2298, 5, 5, 60.00, 0.00, 0),
(1549, 2298, 31, 13, 26.00, 0.00, 0),
(1550, 2299, 26, 3, 30.00, 0.00, 0),
(1551, 2299, 23, 3, 45.00, 0.00, 0),
(1552, 2299, 2, 2, 24.00, 0.00, 0),
(1553, 2299, 27, 3, 36.00, 0.00, 0),
(1554, 2299, 14, 2, 36.00, 0.00, 0),
(1555, 2299, 18, 3, 36.00, 0.00, 0),
(1556, 2299, 21, 2, 40.00, 0.00, 0),
(1557, 2299, 29, 2, 56.00, 0.00, 0),
(1558, 2300, 18, 4, 48.00, 0.00, 0),
(1559, 2300, 9, 2, 40.00, 0.00, 0),
(1560, 2300, 29, 2, 56.00, 0.00, 0),
(1561, 2300, 3, 1, 15.00, 0.00, 0),
(1562, 2300, 10, 1, 25.00, 0.00, 0),
(1563, 2300, 17, 2, 30.00, 0.00, 0),
(1564, 2300, 14, 2, 36.00, 0.00, 0),
(1565, 2300, 9, 2, 40.00, 0.00, 0),
(1566, 2300, 20, 2, 80.00, 0.00, 0),
(1567, 2300, 26, 1, 10.00, 0.00, 0),
(1568, 2300, 27, 3, 36.00, 0.00, 0),
(1569, 2301, 5, 5, 60.00, 0.00, 0),
(1570, 2301, 30, 5, 40.00, 0.00, 0),
(1571, 2302, 31, 18, 36.00, 0.00, 0),
(1572, 2302, 26, 1, 10.00, 0.00, 0),
(1573, 2302, 13, 2, 50.00, 0.00, 0),
(1574, 2302, 26, 4, 40.00, 0.00, 1),
(1575, 2302, 4, 2, 40.00, 0.00, 0),
(1576, 2302, 25, 2, 24.00, 0.00, 0),
(1577, 2302, 18, 1, 12.00, 0.00, 0),
(1578, 2302, 27, 3, 36.00, 0.00, 0),
(1579, 2302, 8, 1, 10.00, 0.00, 0),
(1580, 2302, 24, 1, 18.00, 0.00, 0),
(1581, 2303, 2, 3, 36.00, 0.00, 0),
(1582, 2303, 19, 2, 70.00, 0.00, 0),
(1583, 2303, 19, 1, 35.00, 0.00, 0),
(1584, 2303, 23, 2, 30.00, 0.00, 0),
(1585, 2304, 25, 1, 12.00, 0.00, 0),
(1586, 2304, 6, 2, 30.00, 0.00, 0),
(1587, 2304, 27, 2, 24.00, 0.00, 0),
(1588, 2304, 5, 3, 36.00, 0.00, 0),
(1589, 2304, 19, 2, 70.00, 0.00, 0),
(1590, 2304, 29, 1, 28.00, 0.00, 0),
(1591, 2304, 23, 5, 75.00, 0.00, 0),
(1592, 2304, 19, 2, 70.00, 0.00, 0),
(1593, 2304, 18, 5, 60.00, 0.00, 0),
(1594, 2305, 12, 1, 20.00, 0.00, 0),
(1595, 2305, 19, 1, 35.00, 0.00, 0),
(1596, 2305, 9, 1, 20.00, 0.00, 0),
(1597, 2305, 12, 1, 20.00, 0.00, 0),
(1598, 2305, 19, 1, 35.00, 0.00, 0),
(1599, 2305, 22, 2, 70.00, 0.00, 0),
(1600, 2305, 29, 2, 56.00, 0.00, 0),
(1601, 2305, 20, 2, 80.00, 0.00, 0),
(1602, 2305, 23, 3, 45.00, 0.00, 0),
(1603, 2305, 9, 1, 20.00, 0.00, 0),
(1604, 2306, 13, 2, 50.00, 0.00, 0),
(1605, 2306, 2, 2, 24.00, 0.00, 0),
(1606, 2306, 14, 3, 54.00, 0.00, 0),
(1607, 2306, 3, 2, 30.00, 0.00, 0),
(1608, 2306, 2, 5, 60.00, 0.00, 0),
(1609, 2307, 8, 2, 20.00, 0.00, 0),
(1610, 2307, 1, 3, 30.00, 0.00, 0),
(1611, 2307, 13, 2, 50.00, 0.00, 0),
(1612, 2307, 10, 2, 50.00, 0.00, 0),
(1613, 2307, 24, 1, 18.00, 0.00, 0),
(1614, 2307, 23, 4, 60.00, 0.00, 0),
(1615, 2307, 21, 1, 20.00, 0.00, 0),
(1616, 2307, 23, 3, 45.00, 0.00, 0),
(1617, 2307, 1, 4, 40.00, 0.00, 0),
(1618, 2307, 5, 1, 12.00, 0.00, 0),
(1619, 2307, 1, 1, 10.00, 0.00, 0),
(1620, 2307, 21, 1, 20.00, 0.00, 0),
(1621, 2308, 32, 2, 20.00, 0.00, 0),
(1622, 2308, 4, 2, 40.00, 0.00, 0),
(1623, 2308, 19, 2, 70.00, 0.00, 0),
(1624, 2308, 29, 2, 56.00, 0.00, 0),
(1625, 2308, 4, 1, 20.00, 0.00, 0),
(1626, 2308, 11, 5, 60.00, 0.00, 0),
(1627, 2309, 6, 2, 30.00, 0.00, 0),
(1628, 2309, 26, 1, 10.00, 0.00, 0),
(1629, 2309, 4, 2, 40.00, 0.00, 0),
(1630, 2309, 25, 1, 12.00, 0.00, 0),
(1631, 2310, 32, 3, 30.00, 0.00, 0),
(1632, 2310, 18, 5, 60.00, 0.00, 0),
(1633, 2310, 20, 1, 40.00, 0.00, 0),
(1634, 2310, 6, 1, 15.00, 0.00, 0),
(1635, 2310, 13, 1, 25.00, 0.00, 0),
(1636, 2310, 17, 5, 75.00, 0.00, 0),
(1637, 2310, 23, 5, 75.00, 0.00, 0),
(1638, 2310, 2, 5, 60.00, 0.00, 0),
(1639, 2310, 12, 1, 20.00, 0.00, 0),
(1640, 2310, 3, 5, 75.00, 0.00, 0),
(1641, 2311, 14, 2, 36.00, 0.00, 0),
(1642, 2312, 20, 2, 80.00, 0.00, 0),
(1643, 2312, 4, 1, 20.00, 0.00, 0),
(1644, 2312, 2, 2, 24.00, 0.00, 0),
(1645, 2312, 11, 5, 60.00, 0.00, 0),
(1646, 2312, 26, 3, 30.00, 0.00, 0),
(1647, 2312, 9, 2, 40.00, 0.00, 0),
(1648, 2313, 13, 1, 25.00, 0.00, 0),
(1649, 2313, 7, 3, 54.00, 0.00, 0),
(1650, 2313, 32, 3, 30.00, 0.00, 0),
(1651, 2313, 27, 3, 36.00, 0.00, 0),
(1652, 2313, 18, 5, 60.00, 0.00, 0),
(1653, 2313, 20, 1, 40.00, 0.00, 0),
(1654, 2313, 31, 10, 20.00, 0.00, 1),
(1655, 2313, 3, 3, 45.00, 0.00, 0),
(1656, 2313, 1, 4, 40.00, 0.00, 0),
(1657, 2313, 22, 1, 35.00, 0.00, 0),
(1658, 2313, 8, 4, 40.00, 0.00, 0),
(1659, 2313, 6, 2, 30.00, 0.00, 0),
(1660, 2314, 20, 2, 80.00, 0.00, 0),
(1661, 2314, 5, 4, 48.00, 0.00, 0),
(1662, 2314, 23, 4, 60.00, 0.00, 0),
(1663, 2314, 7, 4, 72.00, 0.00, 0),
(1664, 2315, 18, 4, 48.00, 0.00, 0),
(1665, 2315, 27, 1, 12.00, 0.00, 0),
(1666, 2315, 20, 1, 40.00, 0.00, 0),
(1667, 2315, 10, 1, 25.00, 0.00, 0),
(1668, 2315, 23, 4, 60.00, 0.00, 0),
(1669, 2315, 31, 7, 14.00, 0.00, 0),
(1670, 2315, 10, 1, 25.00, 0.00, 0),
(1671, 2315, 32, 4, 40.00, 0.00, 0),
(1672, 2315, 5, 5, 60.00, 0.00, 0),
(1673, 2315, 19, 1, 35.00, 0.00, 0),
(1674, 2316, 24, 4, 72.00, 0.00, 0),
(1675, 2316, 8, 4, 40.00, 0.00, 0),
(1676, 2316, 3, 2, 30.00, 0.00, 0),
(1677, 2316, 18, 1, 12.00, 0.00, 0),
(1678, 2316, 8, 3, 30.00, 0.00, 0),
(1679, 2317, 6, 4, 60.00, 0.00, 0),
(1680, 2317, 25, 1, 12.00, 0.00, 0),
(1681, 2317, 32, 5, 50.00, 0.00, 0),
(1682, 2318, 26, 3, 30.00, 0.00, 0),
(1683, 2318, 6, 3, 45.00, 0.00, 0),
(1684, 2318, 25, 1, 12.00, 0.00, 0),
(1685, 2318, 31, 13, 26.00, 0.00, 0),
(1686, 2318, 26, 3, 30.00, 0.00, 0),
(1687, 2318, 23, 3, 45.00, 0.00, 0),
(1688, 2318, 17, 4, 60.00, 0.00, 0),
(1689, 2318, 10, 2, 50.00, 0.00, 0),
(1690, 2318, 3, 4, 60.00, 0.00, 0),
(1691, 2318, 9, 1, 20.00, 0.00, 0),
(1692, 2319, 25, 3, 36.00, 0.00, 0),
(1693, 2319, 12, 2, 40.00, 0.00, 0),
(1694, 2320, 27, 2, 24.00, 0.00, 0),
(1695, 2320, 29, 2, 56.00, 0.00, 0),
(1696, 2320, 7, 4, 72.00, 0.00, 0),
(1697, 2320, 21, 1, 20.00, 0.00, 0),
(1698, 2320, 20, 1, 40.00, 0.00, 0),
(1699, 2320, 26, 5, 50.00, 0.00, 0),
(1700, 2320, 10, 1, 25.00, 0.00, 0),
(1701, 2320, 1, 5, 50.00, 0.00, 0),
(1702, 2320, 6, 3, 45.00, 0.00, 0),
(1703, 2320, 18, 3, 36.00, 0.00, 0),
(1704, 2320, 32, 5, 50.00, 0.00, 0),
(1705, 2320, 3, 3, 45.00, 0.00, 0),
(1706, 2321, 12, 2, 40.00, 0.00, 0),
(1707, 2321, 19, 1, 35.00, 0.00, 0),
(1708, 2321, 1, 3, 30.00, 0.00, 0),
(1709, 2321, 7, 1, 18.00, 0.00, 0),
(1710, 2321, 31, 11, 22.00, 0.00, 0),
(1711, 2321, 20, 1, 40.00, 0.00, 0),
(1712, 2322, 8, 3, 30.00, 0.00, 0),
(1713, 2322, 12, 2, 40.00, 0.00, 1),
(1714, 2322, 3, 2, 30.00, 0.00, 0),
(1715, 2322, 14, 3, 54.00, 0.00, 0),
(1716, 2322, 32, 1, 10.00, 0.00, 0),
(1717, 2323, 23, 1, 15.00, 0.00, 0),
(1718, 2323, 6, 4, 60.00, 0.00, 0),
(1719, 2323, 22, 1, 35.00, 0.00, 0),
(1720, 2323, 4, 2, 40.00, 0.00, 0),
(1721, 2323, 29, 1, 28.00, 0.00, 0),
(1722, 2323, 30, 5, 40.00, 0.00, 0),
(1723, 2323, 12, 1, 20.00, 0.00, 0),
(1724, 2323, 2, 4, 48.00, 0.00, 0),
(1725, 2323, 10, 1, 25.00, 0.00, 0),
(1726, 2323, 13, 1, 25.00, 0.00, 0),
(1727, 2323, 23, 5, 75.00, 0.00, 0),
(1728, 2323, 23, 1, 15.00, 0.00, 0),
(1729, 2324, 13, 1, 25.00, 0.00, 0),
(1730, 2324, 1, 1, 10.00, 0.00, 0),
(1731, 2324, 20, 1, 40.00, 0.00, 0),
(1732, 2324, 14, 4, 72.00, 0.00, 0),
(1733, 2324, 30, 4, 32.00, 0.00, 0),
(1734, 2324, 13, 2, 50.00, 0.00, 0),
(1735, 2324, 23, 1, 15.00, 0.00, 0),
(1736, 2324, 22, 1, 35.00, 0.00, 0),
(1737, 2324, 11, 3, 36.00, 0.00, 0),
(1738, 2324, 9, 2, 40.00, 0.00, 0),
(1739, 2324, 32, 3, 30.00, 0.00, 0),
(1740, 2325, 11, 1, 12.00, 0.00, 0),
(1741, 2325, 25, 3, 36.00, 0.00, 0),
(1742, 2325, 25, 1, 12.00, 0.00, 0),
(1743, 2325, 1, 1, 10.00, 0.00, 0),
(1744, 2325, 8, 3, 30.00, 0.00, 0),
(1745, 2325, 7, 5, 90.00, 0.00, 1),
(1746, 2325, 11, 1, 12.00, 0.00, 0),
(1747, 2326, 25, 1, 12.00, 0.00, 0),
(1748, 2326, 4, 2, 40.00, 0.00, 0),
(1749, 2326, 1, 1, 10.00, 0.00, 0),
(1750, 2326, 27, 5, 60.00, 0.00, 0),
(1751, 2326, 11, 1, 12.00, 0.00, 0),
(1752, 2326, 13, 2, 50.00, 0.00, 0),
(1753, 2326, 21, 1, 20.00, 0.00, 0),
(1754, 2326, 18, 1, 12.00, 0.00, 0),
(1755, 2326, 21, 2, 40.00, 0.00, 0),
(1756, 2326, 9, 2, 40.00, 0.00, 0),
(1757, 2326, 8, 2, 20.00, 0.00, 0),
(1758, 2326, 26, 4, 40.00, 0.00, 0),
(1759, 2327, 18, 4, 48.00, 0.00, 0),
(1760, 2327, 19, 1, 35.00, 0.00, 0),
(1761, 2327, 18, 3, 36.00, 0.00, 0),
(1762, 2327, 23, 1, 15.00, 0.00, 0),
(1763, 2327, 21, 2, 40.00, 0.00, 0),
(1764, 2327, 7, 2, 36.00, 0.00, 0),
(1765, 2327, 9, 2, 40.00, 0.00, 0),
(1766, 2327, 7, 2, 36.00, 0.00, 0),
(1767, 2327, 2, 1, 12.00, 0.00, 0),
(1768, 2327, 8, 5, 50.00, 0.00, 0),
(1769, 2327, 26, 3, 30.00, 0.00, 0),
(1770, 2328, 32, 4, 40.00, 0.00, 0),
(1771, 2329, 3, 2, 30.00, 0.00, 0),
(1772, 2330, 11, 3, 36.00, 0.00, 0),
(1773, 2330, 29, 1, 28.00, 0.00, 0),
(1774, 2330, 25, 2, 24.00, 0.00, 0),
(1775, 2330, 5, 5, 60.00, 0.00, 0),
(1776, 2330, 27, 2, 24.00, 0.00, 0),
(1777, 2330, 19, 1, 35.00, 0.00, 0),
(1778, 2330, 9, 2, 40.00, 0.00, 0),
(1779, 2330, 22, 2, 70.00, 0.00, 0),
(1780, 2330, 18, 5, 60.00, 0.00, 0),
(1781, 2330, 19, 2, 70.00, 0.00, 0),
(1782, 2330, 10, 1, 25.00, 0.00, 0),
(1783, 2330, 31, 7, 14.00, 0.00, 0),
(1784, 2331, 21, 1, 20.00, 0.00, 0),
(1785, 2331, 19, 2, 70.00, 0.00, 0),
(1786, 2332, 6, 4, 60.00, 0.00, 0),
(1787, 2332, 22, 2, 70.00, 0.00, 0),
(1788, 2332, 19, 1, 35.00, 0.00, 0),
(1789, 2332, 29, 2, 56.00, 0.00, 0),
(1790, 2332, 17, 1, 15.00, 0.00, 0),
(1791, 2332, 19, 1, 35.00, 0.00, 0),
(1792, 2332, 17, 4, 60.00, 0.00, 0),
(1793, 2332, 27, 5, 60.00, 0.00, 0),
(1794, 2332, 29, 2, 56.00, 0.00, 0),
(1795, 2332, 23, 2, 30.00, 0.00, 0),
(1796, 2332, 24, 1, 18.00, 0.00, 0),
(1797, 2332, 27, 4, 48.00, 0.00, 0),
(1798, 2333, 6, 3, 45.00, 0.00, 0),
(1799, 2333, 4, 2, 40.00, 0.00, 0),
(1800, 2333, 21, 2, 40.00, 0.00, 0),
(1801, 2333, 8, 5, 50.00, 0.00, 0),
(1802, 2333, 31, 14, 28.00, 0.00, 0),
(1803, 2333, 4, 1, 20.00, 0.00, 0),
(1804, 2333, 4, 2, 40.00, 0.00, 0),
(1805, 2334, 31, 14, 28.00, 0.00, 0),
(1806, 2334, 5, 2, 24.00, 0.00, 0),
(1807, 2334, 13, 1, 25.00, 0.00, 0),
(1808, 2334, 29, 1, 28.00, 0.00, 0),
(1809, 2334, 8, 1, 10.00, 0.00, 0),
(1810, 2335, 31, 8, 16.00, 0.00, 0),
(1811, 2335, 32, 3, 30.00, 0.00, 0),
(1812, 2336, 22, 1, 35.00, 0.00, 0),
(1813, 2336, 6, 4, 60.00, 0.00, 0),
(1814, 2336, 29, 1, 28.00, 0.00, 0),
(1815, 2336, 21, 2, 40.00, 0.00, 0),
(1816, 2336, 9, 1, 20.00, 0.00, 0),
(1817, 2336, 27, 3, 36.00, 0.00, 0),
(1818, 2336, 8, 2, 20.00, 0.00, 0),
(1819, 2336, 3, 3, 45.00, 0.00, 0),
(1820, 2336, 5, 5, 60.00, 0.00, 0),
(1821, 2337, 20, 2, 80.00, 0.00, 0),
(1822, 2337, 8, 3, 30.00, 0.00, 0),
(1823, 2337, 11, 1, 12.00, 0.00, 0),
(1824, 2337, 2, 1, 12.00, 0.00, 0),
(1825, 2337, 5, 4, 48.00, 0.00, 0),
(1826, 2337, 5, 4, 48.00, 0.00, 0),
(1827, 2337, 29, 2, 56.00, 0.00, 0),
(1828, 2338, 11, 1, 12.00, 0.00, 0),
(1829, 2339, 27, 4, 48.00, 0.00, 0),
(1830, 2339, 24, 5, 90.00, 0.00, 0),
(1831, 2339, 8, 3, 30.00, 0.00, 0),
(1832, 2340, 9, 2, 40.00, 0.00, 0),
(1833, 2340, 21, 1, 20.00, 0.00, 0),
(1834, 2340, 5, 1, 12.00, 0.00, 0),
(1835, 2340, 4, 2, 40.00, 0.00, 0),
(1836, 2341, 2, 5, 60.00, 0.00, 0),
(1837, 2341, 30, 1, 8.00, 0.00, 0),
(1838, 2341, 3, 1, 15.00, 0.00, 0),
(1839, 2341, 21, 2, 40.00, 0.00, 0),
(1840, 2341, 29, 1, 28.00, 0.00, 0),
(1841, 2342, 18, 3, 36.00, 0.00, 0),
(1842, 2342, 26, 2, 20.00, 0.00, 0),
(1843, 2343, 19, 2, 70.00, 0.00, 0),
(1844, 2343, 7, 1, 18.00, 0.00, 0),
(1845, 2343, 25, 2, 24.00, 0.00, 0),
(1846, 2343, 14, 2, 36.00, 0.00, 0),
(1847, 2343, 30, 2, 16.00, 0.00, 0),
(1848, 2343, 24, 5, 90.00, 0.00, 0),
(1849, 2343, 10, 1, 25.00, 0.00, 0),
(1850, 2343, 7, 4, 72.00, 0.00, 1),
(1851, 2343, 7, 3, 54.00, 0.00, 0),
(1852, 2343, 9, 2, 40.00, 0.00, 0),
(1853, 2343, 11, 3, 36.00, 0.00, 0),
(1854, 2344, 4, 1, 20.00, 0.00, 0),
(1855, 2345, 11, 2, 24.00, 0.00, 0),
(1856, 2345, 26, 5, 50.00, 0.00, 0),
(1857, 2345, 14, 1, 18.00, 0.00, 0),
(1858, 2346, 25, 3, 36.00, 0.00, 0),
(1859, 2346, 30, 1, 8.00, 0.00, 0),
(1860, 2346, 7, 4, 72.00, 0.00, 0),
(1861, 2347, 17, 2, 30.00, 0.00, 0),
(1862, 2347, 20, 1, 40.00, 0.00, 0),
(1863, 2347, 6, 3, 45.00, 0.00, 0),
(1864, 2347, 21, 1, 20.00, 0.00, 0),
(1865, 2348, 3, 2, 30.00, 0.00, 0),
(1866, 2348, 20, 2, 80.00, 0.00, 0),
(1867, 2349, 31, 6, 12.00, 0.00, 0),
(1868, 2349, 2, 1, 12.00, 0.00, 0),
(1869, 2349, 2, 3, 36.00, 0.00, 0),
(1870, 2349, 14, 5, 90.00, 0.00, 0),
(1871, 2349, 24, 5, 90.00, 0.00, 0),
(1872, 2349, 13, 1, 25.00, 0.00, 0),
(1873, 2349, 5, 1, 12.00, 0.00, 0),
(1874, 2349, 14, 3, 54.00, 0.00, 0),
(1875, 2349, 18, 3, 36.00, 0.00, 0),
(1876, 2350, 21, 2, 40.00, 0.00, 0),
(1877, 2350, 27, 3, 36.00, 0.00, 0),
(1878, 2350, 32, 3, 30.00, 0.00, 0),
(1879, 2350, 29, 2, 56.00, 0.00, 0),
(1880, 2350, 14, 3, 54.00, 0.00, 0),
(1881, 2350, 29, 1, 28.00, 0.00, 0),
(1882, 2350, 4, 2, 40.00, 0.00, 0),
(1883, 2350, 30, 2, 16.00, 0.00, 0),
(1884, 2351, 30, 2, 16.00, 0.00, 0),
(1885, 2351, 23, 4, 60.00, 0.00, 0),
(1886, 2351, 26, 4, 40.00, 0.00, 0),
(1887, 2351, 4, 1, 20.00, 0.00, 0),
(1888, 2351, 12, 2, 40.00, 0.00, 0),
(1889, 2351, 21, 1, 20.00, 0.00, 0),
(1890, 2351, 25, 4, 48.00, 0.00, 0),
(1891, 2351, 7, 3, 54.00, 0.00, 0),
(1892, 2351, 19, 1, 35.00, 0.00, 0),
(1893, 2352, 1, 3, 30.00, 0.00, 0),
(1894, 2352, 11, 4, 48.00, 0.00, 0),
(1895, 2352, 13, 1, 25.00, 0.00, 0),
(1896, 2353, 18, 1, 12.00, 0.00, 0),
(1897, 2353, 21, 2, 40.00, 0.00, 0),
(1898, 2354, 6, 3, 45.00, 0.00, 0),
(1899, 2354, 25, 3, 36.00, 0.00, 0),
(1900, 2354, 20, 2, 80.00, 0.00, 0),
(1901, 2354, 32, 4, 40.00, 0.00, 0),
(1902, 2354, 3, 5, 75.00, 0.00, 0),
(1903, 2354, 18, 4, 48.00, 0.00, 0),
(1904, 2354, 6, 2, 30.00, 0.00, 0),
(1905, 2354, 32, 3, 30.00, 0.00, 0),
(1906, 2354, 26, 5, 50.00, 0.00, 0),
(1907, 2355, 5, 3, 36.00, 0.00, 0),
(1908, 2355, 3, 2, 30.00, 0.00, 0),
(1909, 2355, 1, 3, 30.00, 0.00, 0),
(1910, 2355, 2, 4, 48.00, 0.00, 0),
(1911, 2355, 30, 4, 32.00, 0.00, 0),
(1912, 2355, 11, 1, 12.00, 0.00, 0),
(1913, 2355, 3, 3, 45.00, 0.00, 0),
(1914, 2355, 32, 2, 20.00, 0.00, 0),
(1915, 2355, 29, 1, 28.00, 0.00, 0),
(1916, 2355, 23, 4, 60.00, 0.00, 0),
(1917, 2355, 3, 1, 15.00, 0.00, 0),
(1918, 2355, 1, 2, 20.00, 0.00, 0),
(1919, 2356, 2, 3, 36.00, 0.00, 0),
(1920, 2356, 30, 3, 24.00, 0.00, 0),
(1921, 2356, 5, 3, 36.00, 0.00, 0),
(1922, 2357, 29, 2, 56.00, 0.00, 0),
(1923, 2357, 11, 2, 24.00, 0.00, 0),
(1924, 2357, 1, 4, 40.00, 0.00, 0),
(1925, 2357, 29, 1, 28.00, 0.00, 0),
(1926, 2357, 4, 1, 20.00, 0.00, 0),
(1927, 2358, 23, 1, 15.00, 0.00, 0),
(1928, 2358, 17, 4, 60.00, 0.00, 0),
(1929, 2358, 6, 3, 45.00, 0.00, 0),
(1930, 2358, 32, 3, 30.00, 0.00, 0),
(1931, 2358, 26, 2, 20.00, 0.00, 0),
(1932, 2358, 5, 2, 24.00, 0.00, 0),
(1933, 2358, 5, 3, 36.00, 0.00, 0),
(1934, 2358, 2, 4, 48.00, 0.00, 0),
(1935, 2358, 20, 2, 80.00, 0.00, 0),
(1936, 2358, 1, 4, 40.00, 0.00, 0),
(1937, 2358, 20, 1, 40.00, 0.00, 0),
(1938, 2359, 9, 1, 20.00, 0.00, 0),
(1939, 2359, 12, 1, 20.00, 0.00, 0),
(1940, 2359, 20, 2, 80.00, 0.00, 0),
(1941, 2359, 2, 3, 36.00, 0.00, 0),
(1942, 2359, 24, 1, 18.00, 0.00, 0),
(1943, 2360, 30, 1, 8.00, 0.00, 0),
(1944, 2360, 18, 2, 24.00, 0.00, 0),
(1945, 2360, 30, 3, 24.00, 0.00, 0),
(1946, 2360, 7, 5, 90.00, 0.00, 0),
(1947, 2360, 3, 5, 75.00, 0.00, 0),
(1948, 2360, 29, 1, 28.00, 0.00, 0),
(1949, 2360, 29, 2, 56.00, 0.00, 0),
(1950, 2360, 6, 5, 75.00, 0.00, 0),
(1951, 2360, 20, 1, 40.00, 0.00, 0),
(1952, 2360, 6, 4, 60.00, 0.00, 1),
(1953, 2360, 30, 5, 40.00, 0.00, 0),
(1954, 2361, 27, 1, 12.00, 0.00, 0),
(1955, 2361, 12, 2, 40.00, 0.00, 0),
(1956, 2361, 20, 1, 40.00, 0.00, 0),
(1957, 2361, 20, 2, 80.00, 0.00, 0),
(1958, 2361, 13, 1, 25.00, 0.00, 0),
(1959, 2361, 30, 5, 40.00, 0.00, 0),
(1960, 2361, 8, 4, 40.00, 0.00, 0),
(1961, 2362, 1, 5, 50.00, 0.00, 0),
(1962, 2362, 29, 2, 56.00, 0.00, 0),
(1963, 2362, 12, 2, 40.00, 0.00, 0),
(1964, 2362, 11, 4, 48.00, 0.00, 0),
(1965, 2362, 10, 2, 50.00, 0.00, 0),
(1966, 2362, 12, 2, 40.00, 0.00, 0),
(1967, 2362, 4, 2, 40.00, 0.00, 0),
(1968, 2362, 1, 1, 10.00, 0.00, 0),
(1969, 2362, 5, 1, 12.00, 0.00, 0),
(1970, 2362, 19, 2, 70.00, 0.00, 0),
(1971, 2362, 14, 4, 72.00, 0.00, 0),
(1972, 2362, 22, 2, 70.00, 0.00, 0),
(1973, 2363, 3, 2, 30.00, 0.00, 0),
(1974, 2363, 14, 5, 90.00, 0.00, 0),
(1975, 2363, 5, 5, 60.00, 0.00, 0),
(1976, 2364, 3, 3, 45.00, 0.00, 0),
(1977, 2364, 12, 1, 20.00, 0.00, 0),
(1978, 2364, 14, 3, 54.00, 0.00, 0),
(1979, 2365, 24, 1, 18.00, 0.00, 0),
(1980, 2365, 24, 2, 36.00, 0.00, 0),
(1981, 2365, 10, 1, 25.00, 0.00, 0),
(1982, 2365, 25, 4, 48.00, 0.00, 0),
(1983, 2365, 1, 5, 50.00, 0.00, 0),
(1984, 2366, 29, 2, 56.00, 0.00, 0),
(1985, 2366, 17, 4, 60.00, 0.00, 0),
(1986, 2366, 30, 2, 16.00, 0.00, 0),
(1987, 2366, 21, 1, 20.00, 0.00, 0),
(1988, 2366, 27, 1, 12.00, 0.00, 0),
(1989, 2366, 10, 1, 25.00, 0.00, 0),
(1990, 2366, 3, 3, 45.00, 0.00, 0),
(1991, 2366, 9, 2, 40.00, 0.00, 0),
(1992, 2366, 25, 2, 24.00, 0.00, 0),
(1993, 2367, 25, 5, 60.00, 0.00, 0),
(1994, 2367, 31, 7, 14.00, 0.00, 0),
(1995, 2367, 29, 2, 56.00, 0.00, 0),
(1996, 2367, 19, 1, 35.00, 0.00, 0),
(1997, 2367, 26, 1, 10.00, 0.00, 0),
(1998, 2367, 14, 2, 36.00, 0.00, 0),
(1999, 2368, 26, 4, 40.00, 0.00, 0),
(2000, 2368, 27, 2, 24.00, 0.00, 0),
(2001, 2368, 13, 1, 25.00, 0.00, 0),
(2002, 2368, 20, 1, 40.00, 0.00, 0),
(2003, 2369, 4, 1, 20.00, 0.00, 0),
(2004, 2369, 13, 2, 50.00, 0.00, 0),
(2005, 2369, 25, 3, 36.00, 0.00, 0),
(2006, 2369, 6, 1, 15.00, 0.00, 0),
(2007, 2369, 7, 4, 72.00, 0.00, 0),
(2008, 2369, 27, 3, 36.00, 0.00, 0),
(2009, 2370, 6, 2, 30.00, 0.00, 0),
(2010, 2370, 26, 2, 20.00, 0.00, 0),
(2011, 2370, 11, 2, 24.00, 0.00, 0),
(2012, 2370, 20, 1, 40.00, 0.00, 0),
(2013, 2370, 22, 1, 35.00, 0.00, 0),
(2014, 2370, 23, 5, 75.00, 0.00, 0),
(2015, 2370, 24, 4, 72.00, 0.00, 0),
(2016, 2370, 20, 2, 80.00, 0.00, 0),
(2017, 2371, 8, 3, 30.00, 0.00, 0),
(2018, 2371, 24, 2, 36.00, 0.00, 0),
(2019, 2371, 5, 2, 24.00, 0.00, 0),
(2020, 2371, 18, 1, 12.00, 0.00, 0),
(2021, 2371, 26, 1, 10.00, 0.00, 0),
(2022, 2371, 25, 2, 24.00, 0.00, 0),
(2023, 2371, 6, 3, 45.00, 0.00, 0),
(2024, 2371, 32, 2, 20.00, 0.00, 0),
(2025, 2371, 31, 15, 30.00, 0.00, 0),
(2026, 2371, 31, 12, 24.00, 0.00, 0),
(2027, 2371, 14, 5, 90.00, 0.00, 0),
(2028, 2372, 8, 1, 10.00, 0.00, 0),
(2029, 2372, 7, 4, 72.00, 0.00, 0),
(2030, 2372, 26, 1, 10.00, 0.00, 0),
(2031, 2372, 11, 4, 48.00, 0.00, 0),
(2032, 2372, 21, 2, 40.00, 0.00, 0),
(2033, 2372, 10, 2, 50.00, 0.00, 0),
(2034, 2372, 26, 2, 20.00, 0.00, 0),
(2035, 2373, 5, 2, 24.00, 0.00, 0),
(2036, 2374, 29, 1, 28.00, 0.00, 0),
(2037, 2374, 5, 2, 24.00, 0.00, 0),
(2038, 2374, 29, 2, 56.00, 0.00, 0),
(2039, 2374, 10, 2, 50.00, 0.00, 0),
(2040, 2374, 7, 2, 36.00, 0.00, 0),
(2041, 2374, 20, 2, 80.00, 0.00, 0),
(2042, 2374, 6, 5, 75.00, 0.00, 0),
(2043, 2374, 10, 2, 50.00, 0.00, 0),
(2044, 2374, 2, 5, 60.00, 0.00, 0),
(2045, 2374, 17, 2, 30.00, 0.00, 0),
(2046, 2374, 19, 1, 35.00, 0.00, 0),
(2047, 2374, 29, 2, 56.00, 0.00, 0),
(2048, 2375, 14, 2, 36.00, 0.00, 0),
(2049, 2375, 24, 4, 72.00, 0.00, 0),
(2050, 2375, 29, 2, 56.00, 0.00, 0),
(2051, 2375, 24, 2, 36.00, 0.00, 0),
(2052, 2375, 24, 5, 90.00, 0.00, 1),
(2053, 2375, 23, 1, 15.00, 0.00, 0),
(2054, 2375, 19, 1, 35.00, 0.00, 0),
(2055, 2375, 7, 4, 72.00, 0.00, 0),
(2056, 2375, 21, 1, 20.00, 0.00, 0),
(2057, 2376, 12, 2, 40.00, 0.00, 0),
(2058, 2376, 22, 2, 70.00, 0.00, 0),
(2059, 2377, 29, 1, 28.00, 0.00, 0),
(2060, 2377, 23, 1, 15.00, 0.00, 0),
(2061, 2377, 26, 4, 40.00, 0.00, 0),
(2062, 2377, 25, 2, 24.00, 0.00, 0),
(2063, 2378, 26, 1, 10.00, 0.00, 0),
(2064, 2378, 6, 5, 75.00, 0.00, 0),
(2065, 2378, 1, 4, 40.00, 0.00, 0),
(2066, 2378, 23, 3, 45.00, 0.00, 0),
(2067, 2378, 8, 3, 30.00, 0.00, 0),
(2068, 2378, 21, 1, 20.00, 0.00, 0),
(2069, 2378, 8, 2, 20.00, 0.00, 0),
(2070, 2378, 6, 1, 15.00, 0.00, 0),
(2071, 2378, 17, 3, 45.00, 0.00, 0),
(2072, 2379, 19, 2, 70.00, 0.00, 0),
(2073, 2379, 32, 4, 40.00, 0.00, 0),
(2074, 2379, 3, 1, 15.00, 0.00, 0),
(2075, 2379, 13, 1, 25.00, 0.00, 0),
(2076, 2379, 31, 5, 10.00, 0.00, 0),
(2077, 2379, 12, 2, 40.00, 0.00, 0),
(2078, 2380, 10, 2, 50.00, 0.00, 0),
(2079, 2380, 3, 1, 15.00, 0.00, 0),
(2080, 2380, 25, 1, 12.00, 0.00, 0),
(2081, 2380, 21, 1, 20.00, 0.00, 0),
(2082, 2380, 31, 24, 48.00, 0.00, 0),
(2083, 2380, 25, 5, 60.00, 0.00, 1),
(2084, 2380, 17, 2, 30.00, 0.00, 0),
(2085, 2380, 9, 2, 40.00, 0.00, 0),
(2086, 2381, 11, 1, 12.00, 0.00, 0),
(2087, 2381, 4, 1, 20.00, 0.00, 0),
(2088, 2381, 7, 5, 90.00, 0.00, 0),
(2089, 2381, 18, 3, 36.00, 0.00, 0),
(2090, 2381, 31, 16, 32.00, 0.00, 0),
(2091, 2381, 31, 14, 28.00, 0.00, 0),
(2092, 2381, 12, 1, 20.00, 0.00, 0),
(2093, 2382, 14, 4, 72.00, 0.00, 0),
(2094, 2382, 18, 3, 36.00, 0.00, 0),
(2095, 2382, 13, 2, 50.00, 0.00, 0),
(2096, 2383, 17, 3, 45.00, 0.00, 0),
(2097, 2383, 21, 2, 40.00, 0.00, 0),
(2098, 2384, 5, 2, 24.00, 0.00, 0),
(2099, 2384, 32, 5, 50.00, 0.00, 0),
(2100, 2384, 30, 1, 8.00, 0.00, 0),
(2101, 2384, 3, 3, 45.00, 0.00, 0),
(2102, 2384, 5, 1, 12.00, 0.00, 0),
(2103, 2384, 7, 5, 90.00, 0.00, 0),
(2104, 2384, 12, 1, 20.00, 0.00, 0),
(2105, 2384, 11, 4, 48.00, 0.00, 0),
(2106, 2384, 31, 5, 10.00, 0.00, 0),
(2107, 2384, 29, 1, 28.00, 0.00, 0),
(2108, 2385, 29, 1, 28.00, 0.00, 0),
(2109, 2385, 18, 3, 36.00, 0.00, 0),
(2110, 2385, 3, 1, 15.00, 0.00, 0),
(2111, 2385, 9, 1, 20.00, 0.00, 0),
(2112, 2385, 7, 1, 18.00, 0.00, 0),
(2113, 2386, 5, 1, 12.00, 0.00, 0),
(2114, 2386, 24, 4, 72.00, 0.00, 0),
(2115, 2386, 26, 5, 50.00, 0.00, 0),
(2116, 2386, 6, 4, 60.00, 0.00, 0),
(2117, 2386, 3, 3, 45.00, 0.00, 0),
(2118, 2387, 32, 5, 50.00, 0.00, 0),
(2119, 2387, 13, 1, 25.00, 0.00, 0),
(2120, 2388, 2, 2, 24.00, 0.00, 0),
(2121, 2388, 12, 1, 20.00, 0.00, 0),
(2122, 2388, 26, 3, 30.00, 0.00, 0),
(2123, 2388, 1, 3, 30.00, 0.00, 0),
(2124, 2389, 25, 5, 60.00, 0.00, 0),
(2125, 2389, 19, 2, 70.00, 0.00, 0),
(2126, 2389, 9, 1, 20.00, 0.00, 0),
(2127, 2389, 12, 1, 20.00, 0.00, 0),
(2128, 2389, 6, 2, 30.00, 0.00, 0),
(2129, 2389, 1, 1, 10.00, 0.00, 0),
(2130, 2390, 8, 2, 20.00, 0.00, 0),
(2131, 2390, 19, 1, 35.00, 0.00, 0),
(2132, 2390, 3, 1, 15.00, 0.00, 0),
(2133, 2390, 12, 1, 20.00, 0.00, 0),
(2134, 2391, 21, 2, 40.00, 0.00, 0),
(2135, 2391, 8, 4, 40.00, 0.00, 0),
(2136, 2392, 27, 3, 36.00, 0.00, 0),
(2137, 2393, 24, 4, 72.00, 0.00, 0),
(2138, 2393, 17, 5, 75.00, 0.00, 0),
(2139, 2393, 11, 3, 36.00, 0.00, 0),
(2140, 2393, 2, 3, 36.00, 0.00, 0),
(2141, 2393, 31, 16, 32.00, 0.00, 0),
(2142, 2393, 6, 3, 45.00, 0.00, 0),
(2143, 2393, 14, 1, 18.00, 0.00, 0),
(2144, 2393, 6, 3, 45.00, 0.00, 0),
(2145, 2394, 24, 5, 90.00, 0.00, 0),
(2146, 2394, 26, 1, 10.00, 0.00, 0),
(2147, 2395, 26, 4, 40.00, 0.00, 1),
(2148, 2395, 2, 5, 60.00, 0.00, 0),
(2149, 2395, 18, 4, 48.00, 0.00, 0),
(2150, 2395, 31, 20, 40.00, 0.00, 0),
(2151, 2395, 5, 1, 12.00, 0.00, 0),
(2152, 2395, 8, 2, 20.00, 0.00, 0),
(2153, 2395, 25, 3, 36.00, 0.00, 0),
(2154, 2395, 3, 2, 30.00, 0.00, 0),
(2155, 2395, 4, 2, 40.00, 0.00, 0),
(2156, 2395, 1, 2, 20.00, 0.00, 0),
(2157, 2395, 3, 1, 15.00, 0.00, 0),
(2158, 2395, 31, 23, 46.00, 0.00, 0),
(2159, 2396, 32, 4, 40.00, 0.00, 0),
(2160, 2396, 1, 1, 10.00, 0.00, 0),
(2161, 2396, 9, 2, 40.00, 0.00, 0),
(2162, 2396, 4, 2, 40.00, 0.00, 0),
(2163, 2396, 3, 4, 60.00, 0.00, 0),
(2164, 2396, 9, 1, 20.00, 0.00, 0),
(2165, 2396, 10, 2, 50.00, 0.00, 0),
(2166, 2396, 31, 23, 46.00, 0.00, 0),
(2167, 2396, 23, 2, 30.00, 0.00, 0),
(2168, 2396, 22, 2, 70.00, 0.00, 0),
(2169, 2397, 20, 1, 40.00, 0.00, 0),
(2170, 2397, 5, 2, 24.00, 0.00, 0),
(2171, 2397, 17, 2, 30.00, 0.00, 0),
(2172, 2398, 30, 2, 16.00, 0.00, 0),
(2173, 2398, 17, 5, 75.00, 0.00, 0),
(2174, 2398, 21, 2, 40.00, 0.00, 0),
(2175, 2398, 26, 1, 10.00, 0.00, 0),
(2176, 2398, 22, 2, 70.00, 0.00, 0),
(2177, 2398, 13, 2, 50.00, 0.00, 0),
(2178, 2398, 21, 1, 20.00, 0.00, 0),
(2179, 2398, 11, 1, 12.00, 0.00, 0),
(2180, 2398, 23, 1, 15.00, 0.00, 0),
(2181, 2399, 32, 1, 10.00, 0.00, 0),
(2182, 2399, 25, 5, 60.00, 0.00, 0),
(2183, 2399, 22, 2, 70.00, 0.00, 0),
(2184, 2399, 29, 2, 56.00, 0.00, 0),
(2185, 2399, 23, 4, 60.00, 0.00, 0),
(2186, 2399, 5, 3, 36.00, 0.00, 0),
(2187, 2399, 27, 2, 24.00, 0.00, 0),
(2188, 2399, 8, 4, 40.00, 0.00, 0),
(2189, 2399, 14, 3, 54.00, 0.00, 0),
(2190, 2399, 5, 3, 36.00, 0.00, 0),
(2191, 2400, 8, 2, 20.00, 0.00, 0),
(2192, 2400, 32, 4, 40.00, 0.00, 0),
(2193, 2400, 10, 1, 25.00, 0.00, 0),
(2194, 2400, 9, 2, 40.00, 0.00, 0),
(2195, 2400, 6, 2, 30.00, 0.00, 0),
(2196, 2400, 6, 5, 75.00, 0.00, 0),
(2197, 2400, 10, 1, 25.00, 0.00, 0),
(2198, 2400, 25, 4, 48.00, 0.00, 0),
(2199, 2400, 20, 1, 40.00, 0.00, 0),
(2200, 2400, 27, 4, 48.00, 0.00, 0),
(2201, 2401, 24, 1, 18.00, 0.00, 0),
(2202, 2401, 17, 4, 60.00, 0.00, 0),
(2203, 2402, 19, 1, 35.00, 0.00, 0),
(2204, 2402, 31, 5, 10.00, 0.00, 0),
(2205, 2402, 8, 5, 50.00, 0.00, 0),
(2206, 2402, 9, 2, 40.00, 0.00, 0),
(2207, 2402, 5, 3, 36.00, 0.00, 0),
(2208, 2402, 2, 2, 24.00, 0.00, 1),
(2209, 2402, 25, 3, 36.00, 0.00, 0),
(2210, 2402, 11, 4, 48.00, 0.00, 0),
(2211, 2402, 8, 2, 20.00, 0.00, 0),
(2212, 2402, 18, 2, 24.00, 0.00, 0),
(2213, 2402, 17, 1, 15.00, 0.00, 0),
(2214, 2403, 8, 1, 10.00, 0.00, 0),
(2215, 2403, 24, 1, 18.00, 0.00, 0),
(2216, 2403, 11, 2, 24.00, 0.00, 0),
(2217, 2403, 32, 5, 50.00, 0.00, 0),
(2218, 2403, 27, 3, 36.00, 0.00, 0),
(2219, 2403, 20, 2, 80.00, 0.00, 0),
(2220, 2403, 6, 4, 60.00, 0.00, 0),
(2221, 2403, 30, 4, 32.00, 0.00, 0),
(2222, 2403, 4, 1, 20.00, 0.00, 0),
(2223, 2404, 31, 16, 32.00, 0.00, 0),
(2224, 2404, 18, 2, 24.00, 0.00, 0),
(2225, 2404, 32, 2, 20.00, 0.00, 0),
(2226, 2404, 17, 4, 60.00, 0.00, 0),
(2227, 2404, 9, 2, 40.00, 0.00, 0),
(2228, 2404, 5, 1, 12.00, 0.00, 0),
(2229, 2404, 14, 2, 36.00, 0.00, 0),
(2230, 2404, 27, 2, 24.00, 0.00, 0),
(2231, 2404, 22, 2, 70.00, 0.00, 0),
(2232, 2404, 21, 1, 20.00, 0.00, 0),
(2233, 2404, 27, 1, 12.00, 0.00, 0),
(2234, 2405, 3, 2, 30.00, 0.00, 0),
(2235, 2405, 32, 3, 30.00, 0.00, 0),
(2236, 2405, 20, 1, 40.00, 0.00, 0),
(2237, 2405, 5, 5, 60.00, 0.00, 0),
(2238, 2405, 5, 2, 24.00, 0.00, 0),
(2239, 2406, 25, 2, 24.00, 0.00, 0),
(2240, 2406, 26, 1, 10.00, 0.00, 0),
(2241, 2406, 5, 3, 36.00, 0.00, 0),
(2242, 2406, 22, 1, 35.00, 0.00, 0),
(2243, 2406, 6, 1, 15.00, 0.00, 0),
(2244, 2406, 3, 2, 30.00, 0.00, 0),
(2245, 2406, 9, 2, 40.00, 0.00, 0),
(2246, 2407, 12, 2, 40.00, 0.00, 0),
(2247, 2407, 17, 3, 45.00, 0.00, 0),
(2248, 2407, 22, 2, 70.00, 0.00, 1),
(2249, 2407, 30, 2, 16.00, 0.00, 0),
(2250, 2407, 1, 5, 50.00, 0.00, 0),
(2251, 2407, 18, 4, 48.00, 0.00, 0),
(2252, 2407, 18, 2, 24.00, 0.00, 0),
(2253, 2407, 4, 2, 40.00, 0.00, 0),
(2254, 2407, 3, 2, 30.00, 0.00, 0),
(2255, 2407, 26, 1, 10.00, 0.00, 0),
(2256, 2407, 14, 4, 72.00, 0.00, 0),
(2257, 2407, 22, 1, 35.00, 0.00, 0),
(2258, 2408, 18, 2, 24.00, 0.00, 0),
(2259, 2408, 12, 2, 40.00, 0.00, 0),
(2260, 2408, 31, 8, 16.00, 0.00, 0),
(2261, 2408, 4, 1, 20.00, 0.00, 0),
(2262, 2408, 23, 3, 45.00, 0.00, 0),
(2263, 2408, 9, 2, 40.00, 0.00, 0),
(2264, 2409, 13, 1, 25.00, 0.00, 0),
(2265, 2409, 6, 5, 75.00, 0.00, 0),
(2266, 2410, 21, 1, 20.00, 0.00, 0),
(2267, 2410, 19, 2, 70.00, 0.00, 0),
(2268, 2410, 19, 2, 70.00, 0.00, 0),
(2269, 2410, 4, 1, 20.00, 0.00, 0),
(2270, 2411, 27, 3, 36.00, 0.00, 0),
(2271, 2411, 8, 5, 50.00, 0.00, 0),
(2272, 2411, 3, 4, 60.00, 0.00, 0),
(2273, 2411, 21, 1, 20.00, 0.00, 0),
(2274, 2412, 23, 1, 15.00, 0.00, 0),
(2275, 2412, 11, 3, 36.00, 0.00, 0),
(2276, 2412, 7, 4, 72.00, 0.00, 0),
(2277, 2412, 12, 2, 40.00, 0.00, 0),
(2278, 2412, 10, 2, 50.00, 0.00, 0),
(2279, 2412, 22, 2, 70.00, 0.00, 0),
(2280, 2412, 20, 2, 80.00, 0.00, 0),
(2281, 2412, 32, 5, 50.00, 0.00, 0),
(2282, 2412, 2, 1, 12.00, 0.00, 0),
(2283, 2412, 11, 4, 48.00, 0.00, 0),
(2284, 2413, 9, 2, 40.00, 0.00, 0),
(2285, 2413, 17, 1, 15.00, 0.00, 0),
(2286, 2413, 1, 2, 20.00, 0.00, 0),
(2287, 2414, 14, 1, 18.00, 0.00, 0),
(2288, 2414, 30, 1, 8.00, 0.00, 0),
(2289, 2414, 12, 2, 40.00, 0.00, 0),
(2290, 2414, 10, 1, 25.00, 0.00, 0),
(2291, 2414, 26, 3, 30.00, 0.00, 0),
(2292, 2414, 21, 2, 40.00, 0.00, 0),
(2293, 2415, 24, 1, 18.00, 0.00, 0),
(2294, 2415, 31, 20, 40.00, 0.00, 0),
(2295, 2415, 19, 2, 70.00, 0.00, 0),
(2296, 2415, 23, 5, 75.00, 0.00, 0),
(2297, 2415, 12, 1, 20.00, 0.00, 0),
(2298, 2415, 10, 2, 50.00, 0.00, 0),
(2299, 2415, 4, 1, 20.00, 0.00, 0),
(2300, 2415, 17, 1, 15.00, 0.00, 0),
(2301, 2416, 3, 3, 45.00, 0.00, 0),
(2302, 2416, 1, 1, 10.00, 0.00, 0),
(2303, 2416, 3, 5, 75.00, 0.00, 0),
(2304, 2416, 5, 2, 24.00, 0.00, 0),
(2305, 2416, 24, 3, 54.00, 0.00, 0),
(2306, 2416, 31, 8, 16.00, 0.00, 0),
(2307, 2416, 17, 3, 45.00, 0.00, 0),
(2308, 2416, 21, 1, 20.00, 0.00, 0),
(2309, 2416, 5, 3, 36.00, 0.00, 0),
(2310, 2416, 22, 1, 35.00, 0.00, 0),
(2311, 2417, 13, 2, 50.00, 0.00, 0),
(2312, 2417, 3, 2, 30.00, 0.00, 0),
(2313, 2417, 6, 2, 30.00, 0.00, 0),
(2314, 2417, 4, 1, 20.00, 0.00, 0),
(2315, 2417, 20, 2, 80.00, 0.00, 0),
(2316, 2417, 1, 4, 40.00, 0.00, 0),
(2317, 2418, 24, 4, 72.00, 0.00, 0),
(2318, 2418, 30, 4, 32.00, 0.00, 0),
(2319, 2418, 8, 1, 10.00, 0.00, 0),
(2320, 2419, 9, 2, 40.00, 0.00, 0),
(2321, 2419, 25, 5, 60.00, 0.00, 0),
(2322, 2420, 10, 2, 50.00, 0.00, 0),
(2323, 2421, 21, 2, 40.00, 0.00, 0),
(2324, 2422, 13, 1, 25.00, 0.00, 0),
(2325, 2422, 2, 2, 24.00, 0.00, 1),
(2326, 2422, 26, 2, 20.00, 0.00, 0),
(2327, 2422, 14, 1, 18.00, 0.00, 0),
(2328, 2422, 5, 5, 60.00, 0.00, 0),
(2329, 2422, 24, 1, 18.00, 0.00, 0),
(2330, 2422, 24, 1, 18.00, 0.00, 0),
(2331, 2422, 24, 1, 18.00, 0.00, 0),
(2332, 2422, 22, 2, 70.00, 0.00, 0),
(2333, 2422, 19, 1, 35.00, 0.00, 0),
(2334, 2422, 9, 2, 40.00, 0.00, 0),
(2335, 2422, 29, 2, 56.00, 0.00, 0),
(2336, 2423, 26, 2, 20.00, 0.00, 0),
(2337, 2423, 22, 1, 35.00, 0.00, 0),
(2338, 2423, 26, 5, 50.00, 0.00, 0),
(2339, 2424, 23, 5, 75.00, 0.00, 0),
(2340, 2424, 21, 1, 20.00, 0.00, 0),
(2341, 2424, 22, 1, 35.00, 0.00, 0),
(2342, 2424, 5, 4, 48.00, 0.00, 0),
(2343, 2424, 12, 2, 40.00, 0.00, 0),
(2344, 2424, 10, 1, 25.00, 0.00, 0),
(2345, 2424, 11, 4, 48.00, 0.00, 0),
(2346, 2424, 20, 2, 80.00, 0.00, 0),
(2347, 2424, 24, 4, 72.00, 0.00, 0),
(2348, 2424, 4, 2, 40.00, 0.00, 0),
(2349, 2425, 17, 2, 30.00, 0.00, 0),
(2350, 2426, 25, 2, 24.00, 0.00, 0),
(2351, 2426, 30, 4, 32.00, 0.00, 0),
(2352, 2426, 13, 1, 25.00, 0.00, 0),
(2353, 2426, 20, 2, 80.00, 0.00, 0),
(2354, 2426, 19, 1, 35.00, 0.00, 0),
(2355, 2426, 5, 1, 12.00, 0.00, 0),
(2356, 2426, 10, 1, 25.00, 0.00, 0),
(2357, 2426, 21, 1, 20.00, 0.00, 0),
(2358, 2426, 22, 2, 70.00, 0.00, 0),
(2359, 2426, 8, 2, 20.00, 0.00, 0),
(2360, 2427, 13, 2, 50.00, 0.00, 0),
(2361, 2427, 12, 1, 20.00, 0.00, 0),
(2362, 2427, 21, 1, 20.00, 0.00, 0),
(2363, 2427, 20, 1, 40.00, 0.00, 0),
(2364, 2427, 14, 2, 36.00, 0.00, 0),
(2365, 2427, 9, 1, 20.00, 0.00, 0),
(2366, 2427, 1, 1, 10.00, 0.00, 0),
(2367, 2427, 18, 3, 36.00, 0.00, 0),
(2368, 2428, 24, 1, 18.00, 0.00, 0),
(2369, 2428, 25, 3, 36.00, 0.00, 0),
(2370, 2428, 27, 1, 12.00, 0.00, 0),
(2371, 2428, 5, 5, 60.00, 0.00, 0),
(2372, 2428, 3, 2, 30.00, 0.00, 0),
(2373, 2428, 32, 4, 40.00, 0.00, 0),
(2374, 2428, 27, 1, 12.00, 0.00, 0),
(2375, 2428, 5, 4, 48.00, 0.00, 0),
(2376, 2428, 14, 1, 18.00, 0.00, 0),
(2377, 2429, 21, 2, 40.00, 0.00, 0),
(2378, 2429, 26, 3, 30.00, 0.00, 0),
(2379, 2429, 17, 1, 15.00, 0.00, 0),
(2380, 2429, 27, 5, 60.00, 0.00, 0),
(2381, 2429, 6, 3, 45.00, 0.00, 0),
(2382, 2429, 4, 1, 20.00, 0.00, 0),
(2383, 2429, 25, 1, 12.00, 0.00, 0),
(2384, 2429, 20, 2, 80.00, 0.00, 0),
(2385, 2429, 3, 2, 30.00, 0.00, 0),
(2386, 2429, 17, 1, 15.00, 0.00, 0),
(2387, 2429, 2, 4, 48.00, 0.00, 0),
(2388, 2430, 3, 2, 30.00, 0.00, 0),
(2389, 2430, 10, 1, 25.00, 0.00, 0),
(2390, 2430, 26, 1, 10.00, 0.00, 0),
(2391, 2430, 1, 2, 20.00, 0.00, 0),
(2392, 2430, 24, 4, 72.00, 0.00, 0),
(2393, 2430, 8, 1, 10.00, 0.00, 0),
(2394, 2430, 23, 4, 60.00, 0.00, 0),
(2395, 2430, 3, 2, 30.00, 0.00, 0),
(2396, 2430, 12, 1, 20.00, 0.00, 0),
(2397, 2430, 7, 3, 54.00, 0.00, 0),
(2398, 2430, 5, 4, 48.00, 0.00, 0),
(2399, 2431, 10, 2, 50.00, 0.00, 0),
(2400, 2431, 25, 3, 36.00, 0.00, 0),
(2401, 2431, 13, 1, 25.00, 0.00, 0),
(2402, 2431, 21, 1, 20.00, 0.00, 0),
(2403, 2431, 11, 1, 12.00, 0.00, 0),
(2404, 2431, 18, 4, 48.00, 0.00, 0),
(2405, 2431, 10, 2, 50.00, 0.00, 0),
(2406, 2431, 17, 4, 60.00, 0.00, 0),
(2407, 2431, 13, 2, 50.00, 0.00, 0),
(2408, 2431, 17, 5, 75.00, 0.00, 0),
(2409, 2431, 27, 5, 60.00, 0.00, 0),
(2410, 2432, 31, 24, 48.00, 0.00, 0),
(2411, 2432, 26, 4, 40.00, 0.00, 0),
(2412, 2432, 21, 1, 20.00, 0.00, 0),
(2413, 2432, 5, 3, 36.00, 0.00, 0),
(2414, 2432, 11, 2, 24.00, 0.00, 0),
(2415, 2433, 19, 2, 70.00, 0.00, 0),
(2416, 2433, 1, 2, 20.00, 0.00, 0),
(2417, 2433, 12, 2, 40.00, 0.00, 0),
(2418, 2433, 10, 2, 50.00, 0.00, 0),
(2419, 2433, 3, 3, 45.00, 0.00, 0),
(2420, 2433, 4, 2, 40.00, 0.00, 0),
(2421, 2433, 17, 4, 60.00, 0.00, 0),
(2422, 2433, 19, 1, 35.00, 0.00, 0),
(2423, 2433, 24, 5, 90.00, 0.00, 0),
(2424, 2434, 31, 20, 40.00, 0.00, 0),
(2425, 2435, 23, 2, 30.00, 0.00, 0),
(2426, 2435, 26, 1, 10.00, 0.00, 0),
(2427, 2435, 6, 3, 45.00, 0.00, 0),
(2428, 2435, 23, 4, 60.00, 0.00, 0),
(2429, 2435, 1, 2, 20.00, 0.00, 0),
(2430, 2435, 14, 4, 72.00, 0.00, 0),
(2431, 2436, 9, 1, 20.00, 0.00, 0),
(2432, 2436, 6, 5, 75.00, 0.00, 0),
(2433, 2436, 14, 5, 90.00, 0.00, 0),
(2434, 2436, 7, 5, 90.00, 0.00, 0),
(2435, 2436, 31, 8, 16.00, 0.00, 0),
(2436, 2436, 24, 4, 72.00, 0.00, 0),
(2437, 2436, 5, 2, 24.00, 0.00, 0),
(2438, 2436, 22, 2, 70.00, 0.00, 0),
(2439, 2436, 32, 1, 10.00, 0.00, 0),
(2440, 2437, 21, 2, 40.00, 0.00, 0),
(2441, 2437, 13, 1, 25.00, 0.00, 0),
(2442, 2437, 30, 4, 32.00, 0.00, 0),
(2443, 2437, 23, 4, 60.00, 0.00, 0),
(2444, 2437, 4, 1, 20.00, 0.00, 0),
(2445, 2437, 27, 5, 60.00, 0.00, 0),
(2446, 2437, 7, 1, 18.00, 0.00, 0),
(2447, 2437, 2, 3, 36.00, 0.00, 0),
(2448, 2437, 23, 1, 15.00, 0.00, 0),
(2449, 2438, 24, 5, 90.00, 0.00, 0),
(2450, 2438, 13, 2, 50.00, 0.00, 0),
(2451, 2438, 22, 2, 70.00, 0.00, 0),
(2452, 2438, 1, 4, 40.00, 0.00, 0),
(2453, 2438, 3, 2, 30.00, 0.00, 0),
(2454, 2438, 13, 2, 50.00, 0.00, 0),
(2455, 2438, 26, 1, 10.00, 0.00, 0),
(2456, 2438, 4, 1, 20.00, 0.00, 0),
(2457, 2438, 1, 4, 40.00, 0.00, 0),
(2458, 2439, 1, 4, 40.00, 0.00, 0),
(2459, 2439, 32, 1, 10.00, 0.00, 0),
(2460, 2439, 14, 4, 72.00, 0.00, 0),
(2461, 2439, 31, 18, 36.00, 0.00, 0),
(2462, 2439, 19, 2, 70.00, 0.00, 0),
(2463, 2439, 20, 1, 40.00, 0.00, 0),
(2464, 2439, 12, 2, 40.00, 0.00, 0),
(2465, 2439, 21, 1, 20.00, 0.00, 0),
(2466, 2439, 25, 1, 12.00, 0.00, 0),
(2467, 2439, 23, 4, 60.00, 0.00, 0),
(2468, 2439, 31, 18, 36.00, 0.00, 0),
(2469, 2440, 26, 3, 30.00, 0.00, 0),
(2470, 2440, 13, 2, 50.00, 0.00, 0),
(2471, 2440, 24, 3, 54.00, 0.00, 0),
(2472, 2440, 8, 2, 20.00, 0.00, 0),
(2473, 2440, 19, 2, 70.00, 0.00, 0),
(2474, 2440, 26, 4, 40.00, 0.00, 0),
(2475, 2440, 7, 3, 54.00, 0.00, 0),
(2476, 2440, 8, 3, 30.00, 0.00, 0),
(2477, 2440, 13, 2, 50.00, 0.00, 0),
(2478, 2441, 17, 3, 45.00, 0.00, 0),
(2479, 2441, 19, 2, 70.00, 0.00, 1),
(2480, 2441, 21, 2, 40.00, 0.00, 0),
(2481, 2441, 21, 1, 20.00, 0.00, 0),
(2482, 2441, 11, 1, 12.00, 0.00, 0),
(2483, 2442, 29, 2, 56.00, 0.00, 0),
(2484, 2442, 5, 3, 36.00, 0.00, 0),
(2485, 2442, 8, 2, 20.00, 0.00, 0),
(2486, 2442, 22, 1, 35.00, 0.00, 0),
(2487, 2442, 9, 1, 20.00, 0.00, 0),
(2488, 2442, 27, 4, 48.00, 0.00, 0),
(2489, 2442, 3, 3, 45.00, 0.00, 0),
(2490, 2442, 18, 5, 60.00, 0.00, 0),
(2491, 2442, 30, 5, 40.00, 0.00, 0),
(2492, 2442, 6, 3, 45.00, 0.00, 0),
(2493, 2442, 25, 4, 48.00, 0.00, 0),
(2494, 2442, 5, 4, 48.00, 0.00, 0),
(2495, 2443, 24, 1, 18.00, 0.00, 0),
(2496, 2443, 1, 1, 10.00, 0.00, 0),
(2497, 2443, 27, 3, 36.00, 0.00, 0),
(2498, 2443, 20, 2, 80.00, 0.00, 0),
(2499, 2443, 29, 1, 28.00, 0.00, 0),
(2500, 2443, 8, 1, 10.00, 0.00, 0),
(2501, 2443, 19, 2, 70.00, 0.00, 0),
(2502, 2443, 2, 1, 12.00, 0.00, 0),
(2503, 2443, 14, 3, 54.00, 0.00, 0),
(2504, 2443, 22, 2, 70.00, 0.00, 0),
(2505, 2444, 30, 4, 32.00, 0.00, 0),
(2506, 2444, 17, 1, 15.00, 0.00, 0),
(2507, 2444, 25, 1, 12.00, 0.00, 0),
(2508, 2444, 9, 2, 40.00, 0.00, 0),
(2509, 2444, 26, 4, 40.00, 0.00, 0),
(2510, 2444, 1, 1, 10.00, 0.00, 0),
(2511, 2444, 14, 5, 90.00, 0.00, 0),
(2512, 2444, 24, 5, 90.00, 0.00, 0),
(2513, 2444, 11, 4, 48.00, 0.00, 0),
(2514, 2444, 2, 5, 60.00, 0.00, 0),
(2515, 2444, 5, 5, 60.00, 0.00, 0),
(2516, 2445, 9, 1, 20.00, 0.00, 0),
(2517, 2445, 22, 2, 70.00, 0.00, 0),
(2518, 2446, 1, 1, 10.00, 0.00, 0),
(2519, 2446, 3, 2, 30.00, 0.00, 1),
(2520, 2446, 2, 2, 24.00, 0.00, 0),
(2521, 2446, 23, 4, 60.00, 0.00, 0),
(2522, 2446, 14, 1, 18.00, 0.00, 0),
(2523, 2446, 24, 3, 54.00, 0.00, 0),
(2524, 2446, 12, 1, 20.00, 0.00, 0),
(2525, 2446, 27, 2, 24.00, 0.00, 0),
(2526, 2446, 14, 5, 90.00, 0.00, 0),
(2527, 2446, 23, 1, 15.00, 0.00, 0),
(2528, 2446, 27, 1, 12.00, 0.00, 0),
(2529, 2447, 11, 3, 36.00, 0.00, 0),
(2530, 2447, 21, 2, 40.00, 0.00, 0),
(2531, 2447, 5, 1, 12.00, 0.00, 0),
(2532, 2448, 12, 1, 20.00, 0.00, 0),
(2533, 2448, 30, 5, 40.00, 0.00, 0),
(2534, 2448, 26, 4, 40.00, 0.00, 0),
(2535, 2448, 26, 1, 10.00, 0.00, 0),
(2536, 2448, 10, 2, 50.00, 0.00, 0),
(2537, 2448, 26, 5, 50.00, 0.00, 0),
(2538, 2448, 20, 1, 40.00, 0.00, 0),
(2539, 2448, 32, 2, 20.00, 0.00, 0),
(2540, 2448, 1, 4, 40.00, 0.00, 0),
(2541, 2448, 23, 3, 45.00, 0.00, 0),
(2542, 2449, 9, 2, 40.00, 0.00, 0),
(2543, 2449, 11, 3, 36.00, 0.00, 0),
(2544, 2449, 21, 2, 40.00, 0.00, 0),
(2545, 2449, 6, 2, 30.00, 0.00, 0),
(2546, 2449, 2, 1, 12.00, 0.00, 0),
(2547, 2449, 1, 2, 20.00, 0.00, 0),
(2548, 2449, 4, 1, 20.00, 0.00, 0),
(2549, 2449, 9, 2, 40.00, 0.00, 0),
(2550, 2449, 31, 6, 12.00, 0.00, 0),
(2551, 2449, 2, 1, 12.00, 0.00, 0),
(2552, 2449, 18, 5, 60.00, 0.00, 0),
(2553, 2450, 27, 4, 48.00, 0.00, 0),
(2554, 2450, 13, 2, 50.00, 0.00, 0),
(2555, 2450, 6, 2, 30.00, 0.00, 0),
(2556, 2450, 24, 5, 90.00, 0.00, 0),
(2557, 2450, 23, 3, 45.00, 0.00, 0),
(2558, 2451, 14, 2, 36.00, 0.00, 0),
(2559, 2451, 18, 2, 24.00, 0.00, 0),
(2560, 2451, 3, 4, 60.00, 0.00, 0),
(2561, 2451, 17, 5, 75.00, 0.00, 0),
(2562, 2451, 25, 4, 48.00, 0.00, 0),
(2563, 2451, 3, 3, 45.00, 0.00, 0),
(2564, 2451, 32, 1, 10.00, 0.00, 0),
(2565, 2451, 2, 2, 24.00, 0.00, 0),
(2566, 2452, 18, 5, 60.00, 0.00, 0),
(2567, 2452, 30, 3, 24.00, 0.00, 0),
(2568, 2452, 19, 2, 70.00, 0.00, 0),
(2569, 2452, 29, 2, 56.00, 0.00, 0),
(2570, 2452, 6, 3, 45.00, 0.00, 0),
(2571, 2452, 26, 3, 30.00, 0.00, 0),
(2572, 2452, 2, 5, 60.00, 0.00, 0),
(2573, 2452, 18, 4, 48.00, 0.00, 0),
(2574, 2452, 19, 1, 35.00, 0.00, 0),
(2575, 2452, 4, 1, 20.00, 0.00, 0),
(2576, 2452, 11, 4, 48.00, 0.00, 0),
(2577, 2452, 13, 1, 25.00, 0.00, 0),
(2578, 2453, 9, 2, 40.00, 0.00, 0),
(2579, 2453, 10, 2, 50.00, 0.00, 0),
(2580, 2453, 18, 2, 24.00, 0.00, 0),
(2581, 2453, 4, 1, 20.00, 0.00, 0),
(2582, 2453, 10, 2, 50.00, 0.00, 0),
(2583, 2453, 31, 15, 30.00, 0.00, 0),
(2584, 2454, 31, 18, 36.00, 0.00, 0),
(2585, 2454, 3, 1, 15.00, 0.00, 0),
(2586, 2454, 10, 1, 25.00, 0.00, 0),
(2587, 2454, 13, 2, 50.00, 0.00, 0),
(2588, 2455, 2, 2, 24.00, 0.00, 0),
(2589, 2455, 8, 1, 10.00, 0.00, 0),
(2590, 2455, 1, 4, 40.00, 0.00, 0),
(2591, 2455, 21, 1, 20.00, 0.00, 0),
(2592, 2455, 20, 1, 40.00, 0.00, 0),
(2593, 2455, 6, 5, 75.00, 0.00, 0),
(2594, 2455, 18, 5, 60.00, 0.00, 0),
(2595, 2455, 12, 1, 20.00, 0.00, 0),
(2596, 2455, 18, 4, 48.00, 0.00, 0),
(2597, 2456, 24, 1, 18.00, 0.00, 0),
(2598, 2456, 2, 2, 24.00, 0.00, 0),
(2599, 2456, 14, 3, 54.00, 0.00, 0),
(2600, 2456, 31, 5, 10.00, 0.00, 0),
(2601, 2456, 5, 1, 12.00, 0.00, 0),
(2602, 2456, 13, 2, 50.00, 0.00, 0),
(2603, 2456, 5, 1, 12.00, 0.00, 0),
(2604, 2456, 2, 3, 36.00, 0.00, 0),
(2605, 2456, 29, 1, 28.00, 0.00, 0),
(2606, 2456, 18, 4, 48.00, 0.00, 0),
(2607, 2456, 9, 1, 20.00, 0.00, 0),
(2608, 2457, 27, 5, 60.00, 0.00, 0),
(2609, 2457, 5, 5, 60.00, 0.00, 0),
(2610, 2457, 8, 3, 30.00, 0.00, 0),
(2611, 2457, 11, 3, 36.00, 0.00, 0),
(2612, 2457, 31, 14, 28.00, 0.00, 0),
(2613, 2457, 32, 5, 50.00, 0.00, 0),
(2614, 2457, 22, 2, 70.00, 0.00, 0),
(2615, 2458, 10, 2, 50.00, 0.00, 0),
(2616, 2458, 17, 4, 60.00, 0.00, 0),
(2617, 2459, 17, 1, 15.00, 0.00, 0),
(2618, 2459, 26, 3, 30.00, 0.00, 0),
(2619, 2459, 23, 1, 15.00, 0.00, 0),
(2620, 2459, 20, 2, 80.00, 0.00, 0),
(2621, 2459, 6, 1, 15.00, 0.00, 0),
(2622, 2459, 32, 3, 30.00, 0.00, 0),
(2623, 2459, 10, 2, 50.00, 0.00, 0),
(2624, 2459, 4, 2, 40.00, 0.00, 0),
(2625, 2459, 10, 2, 50.00, 0.00, 0),
(2626, 2459, 11, 1, 12.00, 0.00, 0),
(2627, 2459, 2, 4, 48.00, 0.00, 0),
(2628, 2459, 24, 1, 18.00, 0.00, 0),
(2629, 2460, 22, 1, 35.00, 0.00, 0),
(2630, 2460, 12, 1, 20.00, 0.00, 0),
(2631, 2460, 13, 2, 50.00, 0.00, 0),
(2632, 2460, 25, 5, 60.00, 0.00, 0),
(2633, 2460, 31, 15, 30.00, 0.00, 0),
(2634, 2460, 24, 2, 36.00, 0.00, 0),
(2635, 2460, 18, 5, 60.00, 0.00, 0),
(2636, 2460, 25, 5, 60.00, 0.00, 0),
(2637, 2460, 14, 5, 90.00, 0.00, 0),
(2638, 2461, 1, 2, 20.00, 0.00, 0),
(2639, 2461, 26, 1, 10.00, 0.00, 0),
(2640, 2461, 32, 4, 40.00, 0.00, 0),
(2641, 2461, 29, 2, 56.00, 0.00, 0),
(2642, 2461, 31, 6, 12.00, 0.00, 0),
(2643, 2461, 5, 2, 24.00, 0.00, 0),
(2644, 2462, 1, 5, 50.00, 0.00, 0),
(2645, 2462, 32, 1, 10.00, 0.00, 0),
(2646, 2462, 5, 4, 48.00, 0.00, 0),
(2647, 2462, 20, 2, 80.00, 0.00, 0),
(2648, 2462, 5, 1, 12.00, 0.00, 0),
(2649, 2462, 22, 1, 35.00, 0.00, 0),
(2650, 2462, 17, 1, 15.00, 0.00, 0),
(2651, 2462, 27, 3, 36.00, 0.00, 0),
(2652, 2462, 23, 2, 30.00, 0.00, 0),
(2653, 2462, 29, 2, 56.00, 0.00, 0),
(2654, 2463, 24, 4, 72.00, 0.00, 0),
(2655, 2463, 13, 2, 50.00, 0.00, 0),
(2656, 2463, 26, 2, 20.00, 0.00, 0),
(2657, 2464, 29, 2, 56.00, 0.00, 0),
(2658, 2464, 19, 2, 70.00, 0.00, 0),
(2659, 2464, 20, 1, 40.00, 0.00, 0),
(2660, 2464, 17, 3, 45.00, 0.00, 1),
(2661, 2464, 7, 3, 54.00, 0.00, 0),
(2662, 2464, 26, 3, 30.00, 0.00, 0),
(2663, 2464, 12, 1, 20.00, 0.00, 0),
(2664, 2464, 4, 1, 20.00, 0.00, 0),
(2665, 2465, 26, 4, 40.00, 0.00, 0),
(2666, 2465, 32, 1, 10.00, 0.00, 0),
(2667, 2465, 10, 2, 50.00, 0.00, 0),
(2668, 2466, 7, 3, 54.00, 0.00, 0),
(2669, 2466, 22, 1, 35.00, 0.00, 0),
(2670, 2466, 14, 4, 72.00, 0.00, 0),
(2671, 2466, 1, 3, 30.00, 0.00, 0),
(2672, 2466, 17, 3, 45.00, 0.00, 0),
(2673, 2466, 25, 1, 12.00, 0.00, 0),
(2674, 2466, 18, 2, 24.00, 0.00, 0),
(2675, 2466, 10, 2, 50.00, 0.00, 0),
(2676, 2466, 26, 3, 30.00, 0.00, 0),
(2677, 2467, 26, 3, 30.00, 0.00, 0),
(2678, 2467, 10, 2, 50.00, 0.00, 0),
(2679, 2467, 13, 2, 50.00, 0.00, 0),
(2680, 2467, 14, 2, 36.00, 0.00, 0),
(2681, 2467, 1, 3, 30.00, 0.00, 0),
(2682, 2467, 30, 5, 40.00, 0.00, 0),
(2683, 2467, 32, 3, 30.00, 0.00, 0),
(2684, 2467, 22, 2, 70.00, 0.00, 0),
(2685, 2467, 20, 2, 80.00, 0.00, 0),
(2686, 2467, 13, 1, 25.00, 0.00, 0),
(2687, 2467, 13, 2, 50.00, 0.00, 0),
(2688, 2468, 6, 3, 45.00, 0.00, 0),
(2689, 2468, 5, 4, 48.00, 0.00, 0),
(2690, 2469, 27, 4, 48.00, 0.00, 0),
(2691, 2469, 5, 5, 60.00, 0.00, 0),
(2692, 2469, 3, 5, 75.00, 0.00, 0),
(2693, 2469, 32, 1, 10.00, 0.00, 0),
(2694, 2469, 7, 5, 90.00, 0.00, 0),
(2695, 2469, 11, 3, 36.00, 0.00, 0),
(2696, 2469, 23, 2, 30.00, 0.00, 0),
(2697, 2469, 9, 1, 20.00, 0.00, 0),
(2698, 2469, 6, 5, 75.00, 0.00, 0),
(2699, 2469, 10, 1, 25.00, 0.00, 0),
(2700, 2469, 19, 2, 70.00, 0.00, 0),
(2701, 2469, 23, 4, 60.00, 0.00, 1),
(2702, 2470, 13, 1, 25.00, 0.00, 0),
(2703, 2470, 27, 4, 48.00, 0.00, 0),
(2704, 2470, 14, 1, 18.00, 0.00, 0),
(2705, 2470, 27, 1, 12.00, 0.00, 0),
(2706, 2470, 30, 2, 16.00, 0.00, 0),
(2707, 2470, 6, 3, 45.00, 0.00, 0),
(2708, 2470, 8, 4, 40.00, 0.00, 0),
(2709, 2470, 19, 2, 70.00, 0.00, 0),
(2710, 2470, 5, 4, 48.00, 0.00, 0),
(2711, 2470, 10, 2, 50.00, 0.00, 0),
(2712, 2471, 13, 2, 50.00, 0.00, 0),
(2713, 2471, 8, 4, 40.00, 0.00, 0),
(2714, 2471, 11, 3, 36.00, 0.00, 0),
(2715, 2472, 21, 1, 20.00, 0.00, 0),
(2716, 2472, 23, 3, 45.00, 0.00, 0),
(2717, 2472, 30, 2, 16.00, 0.00, 0),
(2718, 2472, 22, 2, 70.00, 0.00, 0),
(2719, 2473, 25, 2, 24.00, 0.00, 0),
(2720, 2473, 23, 5, 75.00, 0.00, 0),
(2721, 2473, 1, 3, 30.00, 0.00, 0),
(2722, 2473, 5, 3, 36.00, 0.00, 0),
(2723, 2474, 5, 2, 24.00, 0.00, 0),
(2724, 2474, 24, 2, 36.00, 0.00, 0),
(2725, 2474, 22, 1, 35.00, 0.00, 0),
(2726, 2474, 13, 2, 50.00, 0.00, 0),
(2727, 2474, 1, 1, 10.00, 0.00, 0),
(2728, 2474, 22, 2, 70.00, 0.00, 0),
(2729, 2474, 19, 2, 70.00, 0.00, 0),
(2730, 2474, 30, 1, 8.00, 0.00, 0),
(2731, 2474, 23, 5, 75.00, 0.00, 0),
(2732, 2475, 1, 1, 10.00, 0.00, 0),
(2733, 2475, 13, 2, 50.00, 0.00, 0),
(2734, 2475, 24, 5, 90.00, 0.00, 0),
(2735, 2475, 17, 5, 75.00, 0.00, 0),
(2736, 2475, 2, 5, 60.00, 0.00, 0),
(2737, 2476, 3, 2, 30.00, 0.00, 0),
(2738, 2476, 19, 1, 35.00, 0.00, 0),
(2739, 2476, 13, 2, 50.00, 0.00, 0),
(2740, 2476, 13, 1, 25.00, 0.00, 0),
(2741, 2476, 23, 3, 45.00, 0.00, 0),
(2742, 2476, 6, 5, 75.00, 0.00, 0),
(2743, 2476, 30, 5, 40.00, 0.00, 0),
(2744, 2476, 12, 2, 40.00, 0.00, 0),
(2745, 2476, 4, 2, 40.00, 0.00, 0),
(2746, 2476, 17, 1, 15.00, 0.00, 0),
(2747, 2476, 13, 1, 25.00, 0.00, 0),
(2748, 2476, 17, 1, 15.00, 0.00, 0),
(2749, 2477, 29, 1, 28.00, 0.00, 0),
(2750, 2477, 13, 2, 50.00, 0.00, 0),
(2751, 2477, 1, 5, 50.00, 0.00, 0),
(2752, 2477, 9, 1, 20.00, 0.00, 0),
(2753, 2478, 31, 12, 24.00, 0.00, 1),
(2754, 2478, 2, 3, 36.00, 0.00, 0),
(2755, 2478, 18, 1, 12.00, 0.00, 0),
(2756, 2478, 5, 5, 60.00, 0.00, 0),
(2757, 2478, 6, 3, 45.00, 0.00, 0),
(2758, 2479, 32, 2, 20.00, 0.00, 0),
(2759, 2480, 29, 1, 28.00, 0.00, 0),
(2760, 2480, 29, 2, 56.00, 0.00, 0),
(2761, 2480, 10, 2, 50.00, 0.00, 1),
(2762, 2481, 8, 5, 50.00, 0.00, 0),
(2763, 2481, 5, 5, 60.00, 0.00, 0),
(2764, 2481, 11, 2, 24.00, 0.00, 1),
(2765, 2481, 5, 5, 60.00, 0.00, 0),
(2766, 2481, 6, 4, 60.00, 0.00, 0),
(2767, 2481, 24, 2, 36.00, 0.00, 0),
(2768, 2481, 10, 2, 50.00, 0.00, 0),
(2769, 2481, 1, 1, 10.00, 0.00, 0),
(2770, 2481, 30, 4, 32.00, 0.00, 0),
(2771, 2481, 5, 3, 36.00, 0.00, 0),
(2772, 2482, 2, 3, 36.00, 0.00, 0),
(2773, 2482, 23, 2, 30.00, 0.00, 0),
(2774, 2482, 18, 2, 24.00, 0.00, 0),
(2775, 2482, 11, 4, 48.00, 0.00, 0),
(2776, 2482, 5, 3, 36.00, 0.00, 0),
(2777, 2483, 3, 1, 15.00, 0.00, 0),
(2778, 2483, 8, 2, 20.00, 0.00, 0),
(2779, 2483, 18, 1, 12.00, 0.00, 0),
(2780, 2483, 5, 1, 12.00, 0.00, 0),
(2781, 2483, 3, 1, 15.00, 0.00, 0),
(2782, 2483, 19, 2, 70.00, 0.00, 0),
(2783, 2483, 25, 2, 24.00, 0.00, 0),
(2784, 2484, 1, 3, 30.00, 0.00, 0),
(2785, 2484, 11, 2, 24.00, 0.00, 0),
(2786, 2484, 22, 2, 70.00, 0.00, 0),
(2787, 2485, 2, 1, 12.00, 0.00, 0),
(2788, 2485, 14, 4, 72.00, 0.00, 0),
(2789, 2485, 23, 3, 45.00, 0.00, 0),
(2790, 2485, 30, 4, 32.00, 0.00, 0),
(2791, 2485, 32, 5, 50.00, 0.00, 0),
(2792, 2485, 2, 4, 48.00, 0.00, 0),
(2793, 2486, 10, 1, 25.00, 0.00, 0),
(2794, 2486, 2, 3, 36.00, 0.00, 0),
(2795, 2486, 19, 1, 35.00, 0.00, 0),
(2796, 2487, 13, 1, 25.00, 0.00, 0),
(2797, 2487, 10, 2, 50.00, 0.00, 0),
(2798, 2487, 8, 4, 40.00, 0.00, 0),
(2799, 2487, 21, 2, 40.00, 0.00, 0),
(2800, 2487, 29, 2, 56.00, 0.00, 0),
(2801, 2487, 3, 5, 75.00, 0.00, 0),
(2802, 2487, 3, 5, 75.00, 0.00, 0),
(2803, 2487, 17, 4, 60.00, 0.00, 0),
(2804, 2487, 24, 3, 54.00, 0.00, 0),
(2805, 2487, 10, 1, 25.00, 0.00, 0),
(2806, 2487, 5, 4, 48.00, 0.00, 0),
(2807, 2488, 19, 1, 35.00, 0.00, 0),
(2808, 2488, 6, 5, 75.00, 0.00, 0),
(2809, 2488, 23, 1, 15.00, 0.00, 0),
(2810, 2488, 31, 5, 10.00, 0.00, 0),
(2811, 2489, 12, 2, 40.00, 0.00, 0),
(2812, 2489, 23, 1, 15.00, 0.00, 0),
(2813, 2489, 6, 1, 15.00, 0.00, 0),
(2814, 2489, 4, 2, 40.00, 0.00, 0),
(2815, 2489, 1, 3, 30.00, 0.00, 0),
(2816, 2489, 31, 16, 32.00, 0.00, 0),
(2817, 2489, 17, 1, 15.00, 0.00, 0),
(2818, 2489, 6, 1, 15.00, 0.00, 0),
(2819, 2489, 7, 5, 90.00, 0.00, 0),
(2820, 2490, 18, 3, 36.00, 0.00, 0),
(2821, 2490, 10, 2, 50.00, 0.00, 0),
(2822, 2490, 12, 1, 20.00, 0.00, 0),
(2823, 2490, 31, 8, 16.00, 0.00, 0),
(2824, 2490, 29, 1, 28.00, 0.00, 0),
(2825, 2490, 2, 5, 60.00, 0.00, 0),
(2826, 2490, 31, 21, 42.00, 0.00, 0),
(2827, 2491, 27, 4, 48.00, 0.00, 0),
(2828, 2491, 20, 2, 80.00, 0.00, 0),
(2829, 2491, 8, 1, 10.00, 0.00, 0),
(2830, 2491, 3, 3, 45.00, 0.00, 0),
(2831, 2491, 30, 2, 16.00, 0.00, 0),
(2832, 2491, 18, 2, 24.00, 0.00, 0),
(2833, 2492, 13, 2, 50.00, 0.00, 0),
(2834, 2492, 17, 5, 75.00, 0.00, 0),
(2835, 2492, 23, 4, 60.00, 0.00, 0),
(2836, 2492, 5, 3, 36.00, 0.00, 0),
(2837, 2492, 30, 2, 16.00, 0.00, 0),
(2838, 2492, 26, 1, 10.00, 0.00, 0),
(2839, 2493, 2, 1, 12.00, 0.00, 0),
(2840, 2493, 4, 1, 20.00, 0.00, 0),
(2841, 2493, 25, 1, 12.00, 0.00, 0),
(2842, 2493, 25, 1, 12.00, 0.00, 0),
(2843, 2493, 31, 17, 34.00, 0.00, 0),
(2844, 2493, 26, 4, 40.00, 0.00, 0),
(2845, 2493, 13, 1, 25.00, 0.00, 0),
(2846, 2493, 3, 3, 45.00, 0.00, 0),
(2847, 2493, 17, 2, 30.00, 0.00, 0),
(2848, 2493, 8, 3, 30.00, 0.00, 0),
(2849, 2494, 23, 3, 45.00, 0.00, 0),
(2850, 2494, 26, 5, 50.00, 0.00, 0),
(2851, 2494, 14, 3, 54.00, 0.00, 0),
(2852, 2494, 8, 2, 20.00, 0.00, 0),
(2853, 2494, 20, 2, 80.00, 0.00, 0),
(2854, 2495, 17, 5, 75.00, 0.00, 0),
(2855, 2495, 24, 4, 72.00, 0.00, 0),
(2856, 2495, 26, 4, 40.00, 0.00, 0),
(2857, 2495, 14, 1, 18.00, 0.00, 0),
(2858, 2495, 1, 1, 10.00, 0.00, 0),
(2859, 2495, 25, 5, 60.00, 0.00, 0),
(2860, 2495, 6, 2, 30.00, 0.00, 0),
(2861, 2495, 8, 4, 40.00, 0.00, 0),
(2862, 2495, 8, 5, 50.00, 0.00, 0),
(2863, 2495, 18, 1, 12.00, 0.00, 0),
(2864, 2495, 30, 1, 8.00, 0.00, 0),
(2865, 2496, 7, 5, 90.00, 0.00, 0),
(2866, 2496, 9, 2, 40.00, 0.00, 0),
(2867, 2496, 29, 2, 56.00, 0.00, 0),
(2868, 2496, 24, 5, 90.00, 0.00, 0),
(2869, 2496, 9, 1, 20.00, 0.00, 0),
(2870, 2496, 9, 2, 40.00, 0.00, 0),
(2871, 2496, 1, 4, 40.00, 0.00, 0),
(2872, 2496, 1, 1, 10.00, 0.00, 0),
(2873, 2496, 19, 1, 35.00, 0.00, 0),
(2874, 2497, 14, 3, 54.00, 0.00, 0),
(2875, 2497, 18, 1, 12.00, 0.00, 0),
(2876, 2497, 24, 5, 90.00, 0.00, 0),
(2877, 2497, 25, 5, 60.00, 0.00, 0),
(2878, 2497, 5, 3, 36.00, 0.00, 0),
(2879, 2497, 14, 4, 72.00, 0.00, 0),
(2880, 2497, 20, 2, 80.00, 0.00, 0),
(2881, 2497, 32, 4, 40.00, 0.00, 0),
(2882, 2497, 32, 5, 50.00, 0.00, 0),
(2883, 2497, 21, 2, 40.00, 0.00, 0),
(2884, 2497, 32, 1, 10.00, 0.00, 0),
(2885, 2498, 1, 3, 30.00, 0.00, 0),
(2886, 2498, 25, 1, 12.00, 0.00, 0),
(2887, 2498, 10, 1, 25.00, 0.00, 0),
(2888, 2498, 2, 4, 48.00, 0.00, 0),
(2889, 2499, 9, 2, 40.00, 0.00, 0),
(2890, 2499, 1, 2, 20.00, 0.00, 0),
(2891, 2499, 9, 1, 20.00, 0.00, 0),
(2892, 2499, 27, 5, 60.00, 0.00, 0),
(2893, 2499, 27, 4, 48.00, 0.00, 0),
(2894, 2500, 8, 2, 20.00, 0.00, 0),
(2895, 2500, 14, 1, 18.00, 0.00, 0),
(2896, 2501, 22, 1, 35.00, 0.00, 0),
(2897, 2501, 7, 5, 90.00, 0.00, 0),
(2898, 2501, 7, 3, 54.00, 0.00, 0),
(2899, 2501, 19, 1, 35.00, 0.00, 0),
(2900, 2501, 10, 2, 50.00, 0.00, 0),
(2901, 2501, 3, 5, 75.00, 0.00, 0),
(2902, 2501, 3, 5, 75.00, 0.00, 0),
(2903, 2502, 5, 4, 48.00, 0.00, 0),
(2904, 2502, 29, 2, 56.00, 0.00, 0),
(2905, 2503, 19, 2, 70.00, 0.00, 0);
INSERT INTO `sales` (`sale_id`, `order_id`, `product_id`, `qty_sold`, `total_price`, `discount_percent`, `qty_returned`) VALUES
(2906, 2503, 31, 10, 20.00, 0.00, 0),
(2907, 2504, 32, 3, 30.00, 0.00, 0),
(2908, 2504, 31, 14, 28.00, 0.00, 0),
(2909, 2505, 26, 5, 50.00, 0.00, 0),
(2910, 2505, 18, 4, 48.00, 0.00, 0),
(2911, 2505, 8, 2, 20.00, 0.00, 0),
(2912, 2505, 23, 1, 15.00, 0.00, 0),
(2913, 2505, 9, 2, 40.00, 0.00, 0),
(2914, 2505, 10, 2, 50.00, 0.00, 0),
(2915, 2505, 10, 2, 50.00, 0.00, 0),
(2916, 2505, 10, 2, 50.00, 0.00, 0),
(2917, 2505, 26, 1, 10.00, 0.00, 0),
(2918, 2505, 10, 2, 50.00, 0.00, 0),
(2919, 2505, 2, 1, 12.00, 0.00, 0),
(2920, 2505, 31, 19, 38.00, 0.00, 0),
(2921, 2506, 31, 13, 26.00, 0.00, 0),
(2922, 2506, 2, 4, 48.00, 0.00, 0),
(2923, 2506, 2, 3, 36.00, 0.00, 0),
(2924, 2506, 30, 4, 32.00, 0.00, 0),
(2925, 2506, 10, 1, 25.00, 0.00, 0),
(2926, 2506, 29, 1, 28.00, 0.00, 0),
(2927, 2506, 9, 1, 20.00, 0.00, 0),
(2928, 2506, 14, 1, 18.00, 0.00, 0),
(2929, 2506, 7, 1, 18.00, 0.00, 0),
(2930, 2506, 2, 3, 36.00, 0.00, 0),
(2931, 2507, 9, 1, 20.00, 0.00, 0),
(2932, 2507, 9, 2, 40.00, 0.00, 0),
(2933, 2507, 6, 3, 45.00, 0.00, 0),
(2934, 2507, 31, 9, 18.00, 0.00, 0),
(2935, 2508, 2, 3, 36.00, 0.00, 0),
(2936, 2508, 17, 2, 30.00, 0.00, 0),
(2937, 2508, 2, 4, 48.00, 0.00, 1),
(2938, 2509, 2, 2, 24.00, 0.00, 0),
(2939, 2509, 17, 1, 15.00, 0.00, 0),
(2940, 2510, 4, 2, 40.00, 0.00, 0),
(2941, 2510, 26, 5, 50.00, 0.00, 0),
(2942, 2510, 9, 1, 20.00, 0.00, 0),
(2943, 2510, 3, 1, 15.00, 0.00, 0),
(2944, 2510, 5, 2, 24.00, 0.00, 0),
(2945, 2510, 23, 5, 75.00, 0.00, 0),
(2946, 2511, 12, 1, 20.00, 0.00, 0),
(2947, 2511, 26, 5, 50.00, 0.00, 0),
(2948, 2511, 17, 1, 15.00, 0.00, 0),
(2949, 2511, 29, 1, 28.00, 0.00, 0),
(2950, 2511, 25, 3, 36.00, 0.00, 0),
(2951, 2511, 4, 2, 40.00, 0.00, 0),
(2952, 2511, 22, 2, 70.00, 0.00, 0),
(2953, 2511, 5, 3, 36.00, 0.00, 0),
(2954, 2511, 4, 2, 40.00, 0.00, 0),
(2955, 2511, 18, 1, 12.00, 0.00, 0),
(2956, 2511, 6, 5, 75.00, 0.00, 0),
(2957, 2511, 29, 2, 56.00, 0.00, 0),
(2958, 2512, 8, 5, 50.00, 0.00, 0),
(2959, 2512, 3, 1, 15.00, 0.00, 0),
(2960, 2512, 23, 5, 75.00, 0.00, 0),
(2961, 2513, 26, 4, 40.00, 0.00, 0),
(2962, 2514, 30, 3, 24.00, 0.00, 0),
(2963, 2514, 10, 2, 50.00, 0.00, 0),
(2964, 2514, 13, 1, 25.00, 0.00, 0),
(2965, 2515, 27, 2, 24.00, 0.00, 0),
(2966, 2515, 30, 4, 32.00, 0.00, 0),
(2967, 2515, 12, 2, 40.00, 0.00, 0),
(2968, 2515, 23, 4, 60.00, 0.00, 0),
(2969, 2515, 12, 1, 20.00, 0.00, 0),
(2970, 2515, 20, 1, 40.00, 0.00, 0),
(2971, 2516, 9, 1, 20.00, 0.00, 0),
(2972, 2516, 32, 3, 30.00, 0.00, 0),
(2973, 2516, 24, 1, 18.00, 0.00, 0),
(2974, 2516, 6, 4, 60.00, 0.00, 0),
(2975, 2516, 6, 1, 15.00, 0.00, 0),
(2976, 2516, 12, 1, 20.00, 0.00, 0),
(2977, 2516, 27, 1, 12.00, 0.00, 0),
(2978, 2517, 9, 2, 40.00, 0.00, 0),
(2979, 2517, 30, 5, 40.00, 0.00, 0),
(2980, 2517, 1, 2, 20.00, 0.00, 0),
(2981, 2518, 8, 3, 30.00, 0.00, 0),
(2982, 2518, 12, 2, 40.00, 0.00, 0),
(2983, 2518, 3, 2, 30.00, 0.00, 0),
(2984, 2519, 30, 4, 32.00, 0.00, 0),
(2985, 2519, 30, 1, 8.00, 0.00, 0),
(2986, 2519, 29, 1, 28.00, 0.00, 0),
(2987, 2519, 27, 5, 60.00, 0.00, 0),
(2988, 2519, 8, 3, 30.00, 0.00, 0),
(2989, 2519, 18, 5, 60.00, 0.00, 0),
(2990, 2519, 8, 1, 10.00, 0.00, 0),
(2991, 2519, 23, 5, 75.00, 0.00, 0),
(2992, 2519, 23, 4, 60.00, 0.00, 0),
(2993, 2519, 14, 2, 36.00, 0.00, 0),
(2994, 2520, 25, 3, 36.00, 0.00, 0),
(2995, 2520, 26, 5, 50.00, 0.00, 0),
(2996, 2520, 13, 1, 25.00, 0.00, 0),
(2997, 2520, 22, 1, 35.00, 0.00, 0),
(2998, 2520, 22, 1, 35.00, 0.00, 0),
(2999, 2520, 3, 5, 75.00, 0.00, 0),
(3000, 2521, 30, 2, 16.00, 0.00, 0),
(3001, 2521, 29, 2, 56.00, 0.00, 0),
(3002, 2521, 19, 2, 70.00, 0.00, 0),
(3003, 2521, 10, 2, 50.00, 0.00, 0),
(3004, 2521, 10, 2, 50.00, 0.00, 0),
(3005, 2521, 14, 4, 72.00, 0.00, 0),
(3006, 2521, 27, 4, 48.00, 0.00, 0),
(3007, 2521, 13, 1, 25.00, 0.00, 0),
(3008, 2521, 5, 3, 36.00, 0.00, 0),
(3009, 2521, 5, 4, 48.00, 0.00, 0),
(3010, 2522, 26, 1, 10.00, 0.00, 0),
(3011, 2522, 13, 1, 25.00, 0.00, 0),
(3012, 2523, 5, 1, 12.00, 0.00, 0),
(3013, 2523, 9, 2, 40.00, 0.00, 0),
(3014, 2523, 32, 4, 40.00, 0.00, 0),
(3015, 2523, 3, 3, 45.00, 0.00, 0),
(3016, 2523, 14, 5, 90.00, 0.00, 0),
(3017, 2523, 9, 1, 20.00, 0.00, 0),
(3018, 2523, 29, 2, 56.00, 0.00, 0),
(3019, 2523, 12, 1, 20.00, 0.00, 0),
(3020, 2523, 13, 2, 50.00, 0.00, 0),
(3021, 2523, 10, 1, 25.00, 0.00, 0),
(3022, 2524, 30, 5, 40.00, 0.00, 0),
(3023, 2524, 3, 4, 60.00, 0.00, 0),
(3024, 2524, 18, 3, 36.00, 0.00, 0),
(3025, 2524, 5, 2, 24.00, 0.00, 0),
(3026, 2524, 13, 2, 50.00, 0.00, 1),
(3027, 2524, 10, 2, 50.00, 0.00, 0),
(3028, 2524, 6, 4, 60.00, 0.00, 0),
(3029, 2525, 5, 3, 36.00, 0.00, 0),
(3030, 2525, 17, 4, 60.00, 0.00, 0),
(3031, 2525, 29, 1, 28.00, 0.00, 0),
(3032, 2525, 13, 2, 50.00, 0.00, 0),
(3033, 2525, 1, 5, 50.00, 0.00, 0),
(3034, 2525, 22, 2, 70.00, 0.00, 0),
(3035, 2525, 17, 3, 45.00, 0.00, 0),
(3036, 2525, 7, 2, 36.00, 0.00, 0),
(3037, 2526, 17, 2, 30.00, 0.00, 0),
(3038, 2526, 2, 2, 24.00, 0.00, 0),
(3039, 2526, 2, 5, 60.00, 0.00, 0),
(3040, 2526, 4, 1, 20.00, 0.00, 0),
(3041, 2526, 30, 1, 8.00, 0.00, 0),
(3042, 2526, 22, 2, 70.00, 0.00, 0),
(3043, 2526, 12, 2, 40.00, 0.00, 0),
(3044, 2526, 12, 1, 20.00, 0.00, 0),
(3045, 2526, 21, 2, 40.00, 0.00, 0),
(3046, 2526, 26, 4, 40.00, 0.00, 0),
(3047, 2527, 20, 2, 80.00, 0.00, 0),
(3048, 2527, 18, 3, 36.00, 0.00, 0),
(3049, 2527, 10, 1, 25.00, 0.00, 0),
(3050, 2527, 25, 3, 36.00, 0.00, 0),
(3051, 2527, 9, 2, 40.00, 0.00, 0),
(3052, 2528, 27, 1, 12.00, 0.00, 0),
(3053, 2528, 9, 1, 20.00, 0.00, 0),
(3054, 2528, 21, 2, 40.00, 0.00, 0),
(3055, 2528, 12, 2, 40.00, 0.00, 0),
(3056, 2528, 31, 12, 24.00, 0.00, 0),
(3057, 2528, 18, 3, 36.00, 0.00, 0),
(3058, 2528, 4, 2, 40.00, 0.00, 0),
(3059, 2528, 24, 5, 90.00, 0.00, 0),
(3060, 2529, 26, 3, 30.00, 0.00, 0),
(3061, 2529, 30, 5, 40.00, 0.00, 0),
(3062, 2529, 5, 5, 60.00, 0.00, 0),
(3063, 2529, 30, 4, 32.00, 0.00, 0),
(3064, 2529, 14, 1, 18.00, 0.00, 0),
(3065, 2529, 22, 2, 70.00, 0.00, 0),
(3066, 2529, 9, 1, 20.00, 0.00, 0),
(3067, 2529, 9, 1, 20.00, 0.00, 0),
(3068, 2529, 10, 2, 50.00, 0.00, 0),
(3069, 2529, 27, 4, 48.00, 0.00, 0),
(3070, 2530, 23, 3, 45.00, 0.00, 0),
(3071, 2530, 32, 5, 50.00, 0.00, 0),
(3072, 2530, 14, 2, 36.00, 0.00, 0),
(3073, 2530, 11, 2, 24.00, 0.00, 1),
(3074, 2530, 1, 1, 10.00, 0.00, 0),
(3075, 2530, 3, 3, 45.00, 0.00, 0),
(3076, 2530, 30, 5, 40.00, 0.00, 0),
(3077, 2530, 21, 2, 40.00, 0.00, 0),
(3078, 2530, 5, 5, 60.00, 0.00, 0),
(3079, 2531, 12, 1, 20.00, 0.00, 0),
(3080, 2531, 8, 3, 30.00, 0.00, 0),
(3081, 2531, 14, 2, 36.00, 0.00, 0),
(3082, 2532, 5, 3, 36.00, 0.00, 0),
(3083, 2532, 22, 1, 35.00, 0.00, 0),
(3084, 2532, 14, 4, 72.00, 0.00, 0),
(3085, 2532, 17, 1, 15.00, 0.00, 0),
(3086, 2532, 18, 2, 24.00, 0.00, 0),
(3087, 2532, 27, 2, 24.00, 0.00, 0),
(3088, 2532, 11, 3, 36.00, 0.00, 0),
(3089, 2532, 5, 3, 36.00, 0.00, 0),
(3090, 2532, 30, 5, 40.00, 0.00, 0),
(3091, 2532, 24, 2, 36.00, 0.00, 0),
(3092, 2532, 29, 2, 56.00, 0.00, 0),
(3093, 2532, 13, 1, 25.00, 0.00, 0),
(3094, 2533, 3, 3, 45.00, 0.00, 0),
(3095, 2534, 13, 1, 25.00, 0.00, 0),
(3096, 2535, 19, 1, 35.00, 0.00, 0),
(3097, 2536, 30, 3, 24.00, 0.00, 0),
(3098, 2536, 24, 5, 90.00, 0.00, 0),
(3099, 2536, 18, 4, 48.00, 0.00, 0),
(3100, 2536, 23, 3, 45.00, 0.00, 0),
(3101, 2536, 32, 3, 30.00, 0.00, 0),
(3102, 2536, 10, 1, 25.00, 0.00, 0),
(3103, 2536, 20, 2, 80.00, 0.00, 0),
(3104, 2536, 20, 2, 80.00, 0.00, 0),
(3105, 2536, 32, 5, 50.00, 0.00, 0),
(3106, 2536, 1, 5, 50.00, 0.00, 0),
(3107, 2537, 19, 2, 70.00, 0.00, 1),
(3108, 2537, 19, 1, 35.00, 0.00, 0),
(3109, 2537, 21, 1, 20.00, 0.00, 0),
(3110, 2537, 29, 1, 28.00, 0.00, 0),
(3111, 2537, 14, 3, 54.00, 0.00, 0),
(3112, 2537, 12, 2, 40.00, 0.00, 0),
(3113, 2537, 3, 2, 30.00, 0.00, 0),
(3114, 2537, 7, 1, 18.00, 0.00, 0),
(3115, 2537, 23, 1, 15.00, 0.00, 0),
(3116, 2538, 23, 4, 60.00, 0.00, 0),
(3117, 2538, 6, 2, 30.00, 0.00, 0),
(3118, 2538, 24, 2, 36.00, 0.00, 0),
(3119, 2538, 11, 2, 24.00, 0.00, 0),
(3120, 2539, 30, 4, 32.00, 0.00, 0),
(3121, 2539, 17, 3, 45.00, 0.00, 0),
(3122, 2539, 24, 1, 18.00, 0.00, 0),
(3123, 2539, 30, 2, 16.00, 0.00, 0),
(3124, 2539, 23, 4, 60.00, 0.00, 0),
(3125, 2539, 24, 5, 90.00, 0.00, 0),
(3126, 2539, 9, 1, 20.00, 0.00, 0),
(3127, 2539, 32, 2, 20.00, 0.00, 0),
(3128, 2540, 9, 1, 20.00, 0.00, 0),
(3129, 2540, 13, 2, 50.00, 0.00, 0),
(3130, 2540, 17, 3, 45.00, 0.00, 0),
(3131, 2540, 7, 4, 72.00, 0.00, 0),
(3132, 2540, 31, 6, 12.00, 0.00, 0),
(3133, 2540, 24, 2, 36.00, 0.00, 0),
(3134, 2541, 18, 3, 36.00, 0.00, 0),
(3135, 2542, 30, 3, 24.00, 0.00, 0),
(3136, 2542, 20, 2, 80.00, 0.00, 0),
(3137, 2542, 20, 2, 80.00, 0.00, 0),
(3138, 2542, 3, 3, 45.00, 0.00, 0),
(3139, 2542, 8, 4, 40.00, 0.00, 0),
(3140, 2542, 29, 1, 28.00, 0.00, 0),
(3141, 2542, 10, 1, 25.00, 0.00, 0),
(3142, 2542, 7, 2, 36.00, 0.00, 0),
(3143, 2542, 23, 4, 60.00, 0.00, 0),
(3144, 2542, 25, 4, 48.00, 0.00, 0),
(3145, 2543, 1, 2, 20.00, 0.00, 0),
(3146, 2543, 11, 5, 60.00, 0.00, 0),
(3147, 2543, 30, 2, 16.00, 0.00, 0),
(3148, 2543, 32, 2, 20.00, 0.00, 0),
(3149, 2543, 11, 1, 12.00, 0.00, 0),
(3150, 2543, 27, 5, 60.00, 0.00, 0),
(3151, 2543, 7, 5, 90.00, 0.00, 0),
(3152, 2543, 13, 2, 50.00, 0.00, 0),
(3153, 2543, 17, 3, 45.00, 0.00, 0),
(3154, 2544, 27, 3, 36.00, 0.00, 0),
(3155, 2544, 11, 2, 24.00, 0.00, 0),
(3156, 2544, 4, 1, 20.00, 0.00, 0),
(3157, 2544, 31, 19, 38.00, 0.00, 0),
(3158, 2544, 22, 2, 70.00, 0.00, 0),
(3159, 2545, 2, 1, 12.00, 0.00, 0),
(3160, 2545, 8, 3, 30.00, 0.00, 0),
(3161, 2545, 17, 1, 15.00, 0.00, 0),
(3162, 2545, 21, 2, 40.00, 0.00, 0),
(3163, 2546, 32, 4, 40.00, 0.00, 0),
(3164, 2546, 8, 1, 10.00, 0.00, 0),
(3165, 2546, 22, 1, 35.00, 0.00, 0),
(3166, 2546, 32, 1, 10.00, 0.00, 0),
(3167, 2546, 22, 1, 35.00, 0.00, 0),
(3168, 2546, 5, 2, 24.00, 0.00, 0),
(3169, 2546, 32, 5, 50.00, 0.00, 0),
(3170, 2546, 5, 1, 12.00, 0.00, 0),
(3171, 2547, 23, 1, 15.00, 0.00, 0),
(3172, 2547, 23, 3, 45.00, 0.00, 1),
(3173, 2547, 4, 2, 40.00, 0.00, 0),
(3174, 2547, 23, 2, 30.00, 0.00, 0),
(3175, 2547, 3, 5, 75.00, 0.00, 0),
(3176, 2547, 31, 21, 42.00, 0.00, 0),
(3177, 2548, 24, 1, 18.00, 0.00, 0),
(3178, 2549, 6, 6, 90.00, 0.00, 0),
(3179, 2550, 4, 1, 20.00, 0.00, 0),
(3180, 2550, 23, 1, 15.00, 0.00, 0),
(3181, 2551, 23, 1, 12.00, 20.00, 0),
(3182, 2551, 26, 1, 8.00, 20.00, 0),
(3183, 2551, 5, 1, 9.60, 20.00, 0),
(3184, 2552, 12, 1, 20.00, 0.00, 0),
(3185, 2552, 17, 1, 15.00, 0.00, 0),
(3186, 2552, 29, 1, 28.00, 0.00, 0),
(3187, 2552, 18, 1, 12.00, 0.00, 0),
(3188, 2553, 22, 2, 66.50, 5.00, 0),
(3189, 2553, 23, 1, 14.25, 5.00, 0),
(3190, 2554, 14, 7, 126.00, 0.00, 0),
(3191, 2555, 26, 1, 9.00, 10.00, 0),
(3192, 2555, 14, 1, 16.20, 10.00, 0),
(3193, 2555, 8, 1, 9.00, 10.00, 0),
(3194, 2556, 23, 1, 13.50, 10.00, 0),
(3195, 2556, 8, 1, 9.00, 10.00, 0),
(3196, 2556, 14, 1, 16.20, 10.00, 0),
(3197, 2556, 26, 1, 9.00, 10.00, 0),
(3198, 2557, 26, 21, 52.50, 75.00, 0),
(3199, 2557, 12, 15, 75.00, 75.00, 0),
(3200, 2558, 18, 1, 11.40, 5.00, 1),
(3201, 2558, 11, 1, 11.40, 5.00, 1),
(3202, 2558, 9, 1, 19.00, 5.00, 0),
(3203, 2559, 33, 1, 4.00, 20.00, 1),
(3204, 2559, 10, 1, 20.00, 20.00, 1),
(3205, 2559, 14, 1, 14.40, 20.00, 1),
(3206, 2560, 18, 1, 11.40, 5.00, 0),
(3207, 2560, 8, 1, 9.50, 5.00, 0),
(3208, 2560, 23, 1, 14.25, 5.00, 0),
(3209, 2560, 33, 1, 4.75, 5.00, 0),
(3210, 2561, 2, 1, 12.00, 0.00, 0),
(3211, 2561, 4, 1, 20.00, 0.00, 0),
(3212, 2562, 22, 5, 87.50, 50.00, 5),
(3213, 2562, 7, 5, 45.00, 50.00, 0),
(3214, 2562, 18, 5, 30.00, 50.00, 0),
(3215, 2563, 7, 1, 14.40, 20.00, 1),
(3216, 2563, 4, 1, 16.00, 20.00, 0),
(3217, 2563, 14, 3, 43.20, 20.00, 0),
(3218, 2563, 18, 2, 19.20, 20.00, 0),
(3219, 2563, 3, 1, 12.00, 20.00, 0),
(3220, 2564, 22, 20, 560.00, 20.00, 0),
(3221, 2564, 2, 19, 182.40, 20.00, 0),
(3222, 2564, 23, 14, 168.00, 20.00, 0),
(3223, 2565, 3, 1, 12.90, 14.00, 1),
(3224, 2565, 17, 3, 38.70, 14.00, 0),
(3225, 2565, 19, 4, 120.40, 14.00, 0),
(3226, 2565, 31, 3, 5.16, 14.00, 0),
(3227, 2565, 4, 4, 68.80, 14.00, 0),
(3228, 2565, 13, 2, 43.00, 14.00, 0),
(3229, 2565, 9, 1, 17.20, 14.00, 0),
(3230, 2566, 13, 1, 20.00, 20.00, 0),
(3231, 2566, 5, 1, 9.60, 20.00, 0),
(3232, 2566, 24, 1, 14.40, 20.00, 0),
(3233, 2566, 22, 1, 28.00, 20.00, 0),
(3234, 2567, 24, 1, 18.00, 0.00, 0),
(3235, 2567, 22, 1, 35.00, 0.00, 0),
(3236, 2567, 28, 1, 30.00, 0.00, 0),
(3237, 2567, 1, 47, 470.00, 0.00, 0);

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
(92, 12, 'ingredient', 4, 8, '[Correction] +8', '2025-11-21 02:46:28'),
(93, 28, 'product', 3, -2, '[Recall] Spoiled', '2025-11-25 21:20:53'),
(94, 22, 'product', 3, 25, '[Production] Newly Baked', '2025-11-30 22:56:09'),
(95, 24, 'product', 3, 2, '[Production] newly baked', '2025-12-03 10:02:41'),
(96, 7, 'ingredient', 3, 10, 'New Stock', '2025-12-03 10:05:07'),
(97, 24, 'product', 3, -6, '[Recall] Spoiled (Undone)', '2025-12-03 10:06:24'),
(98, 33, 'product', 3, 1, '[Production] newly baked', '2025-12-03 10:09:39'),
(99, 10, 'product', 3, -1, '[Return Disposal] Spoiled Item', '2025-12-03 23:33:18'),
(100, 14, 'product', 3, -1, '[Return Disposal] Spoiled Item', '2025-12-03 23:34:11'),
(101, 11, 'product', 3, -1, '[Return Disposal] Spoiled Item', '2025-12-03 23:35:45'),
(102, 22, 'product', 3, -5, '[Return Disposal] ayoko', '2025-12-05 14:13:27'),
(103, 7, 'product', 3, -1, '[Return Disposal] Wrong item', '2025-12-05 14:31:09'),
(104, 7, 'ingredient', 3, 500, '', '2025-12-05 14:36:10'),
(105, 20, 'ingredient', 3, 60, '', '2025-12-05 14:36:36'),
(106, 28, 'ingredient', 3, 7, 'Weekly restock', '2025-12-05 14:37:23'),
(107, 12, 'ingredient', 3, 240, 'Newly bought', '2025-12-05 14:38:49'),
(108, 6, 'ingredient', 3, 500, 'Newly bought', '2025-12-05 14:39:11'),
(109, 7, 'ingredient', 3, 1000, 'Newly bought', '2025-12-05 14:39:28'),
(110, 7, 'ingredient', 3, 250, 'Newly bought', '2025-12-05 14:40:30'),
(111, 34, 'ingredient', 3, 750, 'Newly bought', '2025-12-05 14:40:42'),
(112, 28, 'ingredient', 3, 5, 'Newly bought', '2025-12-05 14:40:54'),
(113, 12, 'ingredient', 3, 600, 'Newly bought', '2025-12-05 14:41:11'),
(114, 14, 'ingredient', 3, 60, 'Newly bought', '2025-12-05 14:41:27'),
(115, 8, 'ingredient', 3, 200, 'Newly bought', '2025-12-05 14:41:51'),
(116, 7, 'ingredient', 3, 2500, 'Newly bought', '2025-12-05 14:42:52'),
(117, 24, 'product', 3, 6, '[Undo Recall] Reversing Adj #97', '2025-12-05 14:47:37'),
(118, 24, 'product', 3, -5, '[Recall] Spoilage', '2025-12-05 14:48:21'),
(119, 22, 'product', 3, 2, '[Production] Newly Baked ', '2025-12-05 15:03:48'),
(120, 22, 'product', 3, 1, '[Production] Newly Baked', '2025-12-05 15:04:30'),
(121, 24, 'product', 3, 6, '[Production] Newly Baked', '2025-12-05 15:05:35'),
(122, 3, 'product', 3, -1, '[Return Disposal] ayoko ', '2025-12-05 15:07:07'),
(123, 33, 'product', 3, 2, '[Production] Newly Baked', '2025-12-05 15:08:44'),
(124, 33, 'product', 3, 5, '[Production] Newly Baked', '2025-12-05 15:09:21'),
(125, 2, 'product', 3, 2, '[Production] Newly Baked', '2025-12-05 15:10:26'),
(126, 22, 'product', 3, 1, '[Production] Newly Baked', '2025-12-05 15:16:24'),
(127, 22, 'product', 3, 1, '[Production] Newly Baked', '2025-12-05 15:17:19'),
(128, 2, 'product', 3, 2, '[Production] Newly Baked', '2025-12-05 15:21:14'),
(129, 7, 'ingredient', 3, -0.5, '[Used] Production of 22', '2025-12-05 15:26:01'),
(130, 34, 'ingredient', 3, -15, '[Used] Production of 22', '2025-12-05 15:26:01'),
(131, 28, 'ingredient', 3, -1, '[Used] Production of 22', '2025-12-05 15:26:01'),
(132, 12, 'ingredient', 3, -0.12, '[Used] Production of 22', '2025-12-05 15:26:01'),
(133, 14, 'ingredient', 3, -0.133333, '[Used] Production of 22', '2025-12-05 15:26:01'),
(134, 8, 'ingredient', 3, -0.2, '[Used] Production of 22', '2025-12-05 15:26:01'),
(135, 22, 'product', 3, 1, '[Production] newly baked', '2025-12-05 15:26:01'),
(136, 7, 'ingredient', 3, -0.5, '[Used] Production of 22', '2025-12-05 15:26:14'),
(137, 34, 'ingredient', 3, -15, '[Used] Production of 22', '2025-12-05 15:26:14'),
(138, 28, 'ingredient', 3, -1, '[Used] Production of 22', '2025-12-05 15:26:14'),
(139, 12, 'ingredient', 3, -0.12, '[Used] Production of 22', '2025-12-05 15:26:14'),
(140, 14, 'ingredient', 3, -0.133333, '[Used] Production of 22', '2025-12-05 15:26:14'),
(141, 8, 'ingredient', 3, -0.2, '[Used] Production of 22', '2025-12-05 15:26:14'),
(142, 22, 'product', 3, 1, '[Production] newly baked', '2025-12-05 15:26:14'),
(143, 6, 'ingredient', 8, -2, '[Used] Production of 28', '2025-12-05 15:27:05'),
(144, 37, 'ingredient', 8, -0.004, '[Used] Production of 28', '2025-12-05 15:27:05'),
(145, 10, 'ingredient', 8, -0.04, '[Used] Production of 28', '2025-12-05 15:27:05'),
(146, 15, 'ingredient', 8, -1.3, '[Used] Production of 28', '2025-12-05 15:27:05'),
(147, 11, 'ingredient', 8, -30, '[Used] Production of 28', '2025-12-05 15:27:05'),
(148, 28, 'product', 8, 2, '[Production] Newly Baked ', '2025-12-05 15:27:05'),
(149, 7, 'ingredient', 8, -0.5, '[Used] Production of 22', '2025-12-05 15:27:39'),
(150, 34, 'ingredient', 8, -15, '[Used] Production of 22', '2025-12-05 15:27:39'),
(151, 28, 'ingredient', 8, -1, '[Used] Production of 22', '2025-12-05 15:27:39'),
(152, 12, 'ingredient', 8, -0.12, '[Used] Production of 22', '2025-12-05 15:27:39'),
(153, 14, 'ingredient', 8, -0.133333, '[Used] Production of 22', '2025-12-05 15:27:39'),
(154, 8, 'ingredient', 8, -0.2, '[Used] Production of 22', '2025-12-05 15:27:39'),
(155, 22, 'product', 8, 1, '[Production] Newly Baked ', '2025-12-05 15:27:39'),
(156, 7, 'ingredient', 8, -0.5, '[Used] Production of 22', '2025-12-05 15:28:02'),
(157, 34, 'ingredient', 8, -15, '[Used] Production of 22', '2025-12-05 15:28:02'),
(158, 28, 'ingredient', 8, -1, '[Used] Production of 22', '2025-12-05 15:28:02'),
(159, 12, 'ingredient', 8, -0.12, '[Used] Production of 22', '2025-12-05 15:28:02'),
(160, 14, 'ingredient', 8, -0.133333, '[Used] Production of 22', '2025-12-05 15:28:02'),
(161, 8, 'ingredient', 8, -0.2, '[Used] Production of 22', '2025-12-05 15:28:02'),
(162, 22, 'product', 8, 1, '[Production] Newly Baked ', '2025-12-05 15:28:02'),
(163, 7, 'ingredient', 3, -1, '[Used] Production of 22', '2025-12-05 16:47:49'),
(164, 34, 'ingredient', 3, -30, '[Used] Production of 22', '2025-12-05 16:47:49'),
(165, 28, 'ingredient', 3, -2, '[Used] Production of 22', '2025-12-05 16:47:49'),
(166, 12, 'ingredient', 3, -0.24, '[Used] Production of 22', '2025-12-05 16:47:49'),
(167, 14, 'ingredient', 3, -0.266667, '[Used] Production of 22', '2025-12-05 16:47:49'),
(168, 8, 'ingredient', 3, -0.4, '[Used] Production of 22', '2025-12-05 16:47:49'),
(169, 22, 'product', 3, 2, '[Production] newly baked', '2025-12-05 16:47:49'),
(170, 6, 'ingredient', 3, -0.833333, '[Used] Production of 1', '2025-12-05 16:48:14'),
(171, 14, 'ingredient', 3, -0.0555556, '[Used] Production of 1', '2025-12-05 16:48:14'),
(172, 13, 'ingredient', 3, -0.0833333, '[Used] Production of 1', '2025-12-05 16:48:14'),
(173, 8, 'ingredient', 3, -0.125, '[Used] Production of 1', '2025-12-05 16:48:14'),
(174, 15, 'ingredient', 3, -0.416667, '[Used] Production of 1', '2025-12-05 16:48:14'),
(175, 11, 'ingredient', 3, -16.6667, '[Used] Production of 1', '2025-12-05 16:48:14'),
(176, 1, 'product', 3, 20, '[Production] newly baked', '2025-12-05 16:48:14'),
(177, 28, 'product', 3, -2, '[Recall] Spoiled', '2025-12-05 16:56:04');

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
(3, 'gian123', '$2y$10$zL/3JN1J36dJ6Vs7pRo4cOS6rRfAd/2i/1hNdl3DxbyXQyqLZy.QW', 'manager', 'givano550@gmail.com', '09945005100', 0, '2025-10-20 05:50:57'),
(4, 'camile123', '$2y$10$qyWiODawyQR7WIdBmOMqae0MkajibrLovW86SDp96rqS1XzJbMTKO', 'assistant_manager', NULL, '09935581868', 0, '2025-11-19 16:39:05'),
(5, 'cashier1', '$2y$10$FOeYrbnt3pBbl4/9ToiVIuvvzz4/JacCm17MGRrM2yhv7I.kzHr4a', 'assistant_manager', NULL, '09359840820', 0, '2025-11-19 18:05:26'),
(6, 'asstntmngr1', '$2y$10$Y.4anQBnSUyBarvYmRfZROCnfph70nJTmRnLicyHoUk8Upp4NBpo.', 'cashier', NULL, '09123456789', 0, '2025-11-19 18:18:48'),
(8, 'Dreed123', '$2y$10$tNtMlLi8a3jFntF3iGk4g.NBmCEtnIzwU1T9VWfFLKgVGDvpEJfQm', 'assistant_manager', NULL, '09923142756', 0, '2025-12-05 07:10:02'),
(9, 'janjan0618', '$2y$10$omfj4jN9t3afhqchb0JN7uyhVGQl/Thb3J8rpXxbFqJbyH9J9mALa', 'cashier', NULL, '09368822967', 0, '2025-12-05 07:11:46'),
(13, 'managertrial', '$2y$10$DELR8VkFH.mqq63/wlnUneVqvHNAk9SW4KutPKsRO79IheUCVURGe', 'manager', NULL, '09123456788', 0, '2025-12-08 03:50:41');

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

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_activelowstockalerts`  AS SELECT `a`.`alert_id` AS `alert_id`, `a`.`ingredient_id` AS `ingredient_id`, `i`.`name` AS `ingredient_name`, `i`.`stock_qty` AS `current_stock`, `i`.`reorder_level` AS `reorder_level`, `a`.`message` AS `message`, `a`.`date_triggered` AS `date_triggered` FROM (`alerts` `a` join `ingredients` `i` on(`a`.`ingredient_id` = `i`.`ingredient_id`)) WHERE `a`.`status` = 'unread' ;

-- --------------------------------------------------------

--
-- Structure for view `view_discontinuedproducts`
--
DROP TABLE IF EXISTS `view_discontinuedproducts`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_discontinuedproducts`  AS SELECT `products`.`product_id` AS `product_id`, `products`.`name` AS `name`, `products`.`price` AS `price`, `products`.`stock_qty` AS `stock_qty`, `products`.`status` AS `status`, `products`.`stock_unit` AS `stock_unit`, `products`.`is_sellable` AS `is_sellable` FROM `products` WHERE `products`.`status` = 'discontinued' ORDER BY `products`.`name` ASC ;

-- --------------------------------------------------------

--
-- Structure for view `view_ingredientstocklevel`
--
DROP TABLE IF EXISTS `view_ingredientstocklevel`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_ingredientstocklevel`  AS SELECT `i`.`ingredient_id` AS `ingredient_id`, `i`.`name` AS `name`, `i`.`unit` AS `unit`, coalesce(sum(`ib`.`quantity`),0) AS `stock_qty`, `i`.`reorder_level` AS `reorder_level`, coalesce(sum(`ib`.`quantity`),0) - `i`.`reorder_level` AS `stock_surplus` FROM (`ingredients` `i` left join `ingredient_batches` `ib` on(`i`.`ingredient_id` = `ib`.`ingredient_id`)) GROUP BY `i`.`ingredient_id` ;

-- --------------------------------------------------------

--
-- Structure for view `view_productinventory`
--
DROP TABLE IF EXISTS `view_productinventory`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_productinventory`  AS SELECT `p`.`product_id` AS `product_id`, `p`.`name` AS `name`, `p`.`price` AS `price`, `p`.`image_url` AS `image_url`, `p`.`stock_qty` AS `stock_qty`, `p`.`status` AS `status`, `p`.`stock_unit` AS `stock_unit`, `p`.`is_sellable` AS `is_sellable` FROM `products` AS `p` WHERE `p`.`status` in ('available','recalled') ;

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
  MODIFY `batch_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=83;

--
-- AUTO_INCREMENT for table `login_history`
--
ALTER TABLE `login_history`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=189;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `order_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2568;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `reset_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `production`
--
ALTER TABLE `production`
  MODIFY `production_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

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
  MODIFY `recipe_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=218;

--
-- AUTO_INCREMENT for table `returns`
--
ALTER TABLE `returns`
  MODIFY `return_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `sale_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3238;

--
-- AUTO_INCREMENT for table `stock_adjustments`
--
ALTER TABLE `stock_adjustments`
  MODIFY `adjustment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=178;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

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
