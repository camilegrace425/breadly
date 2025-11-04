-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 04, 2025 at 02:46 AM
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
CREATE DEFINER=`root`@`localhost` PROCEDURE `AdminGetManagers` ()   BEGIN
    SELECT user_id, username, phone_number
    FROM users
    WHERE role = 'manager' AND phone_number IS NOT NULL AND phone_number != '';
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `DashboardGetActiveLowStockAlerts` (IN `p_limit` INT)   BEGIN
    SELECT * FROM view_ActiveLowStockAlerts
    ORDER BY current_stock ASC
    LIMIT p_limit;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `DashboardGetLowStockAlertsCount` ()   BEGIN
    SELECT COUNT(*) AS alertCount FROM view_ActiveLowStockAlerts;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `DashboardGetRecalledStockValue` ()   BEGIN
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
        sa.reason LIKE '%recall%';
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `DashboardGetSalesSummaryByDateRange` (IN `p_date_start` DATE, IN `p_date_end` DATE)   BEGIN
    SELECT
        COUNT(sale_id) AS totalSales,
        SUM(total_price) AS totalRevenue
    FROM
        sales
    WHERE
        `date` BETWEEN p_date_start AND p_date_end;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `IngredientAdd` (IN `name` VARCHAR(100), IN `unit` VARCHAR(50), IN `stock_qty` FLOAT, IN `reorder_level` FLOAT)   BEGIN
    INSERT INTO ingredients(name, unit, stock_qty, reorder_level)
    VALUES (name, unit, stock_qty, reorder_level);

    SELECT LAST_INSERT_ID() AS new_ingredient_id;
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `IngredientRestock` (IN `p_ingredient_id` INT, IN `p_user_id` INT, IN `p_added_qty` FLOAT)   BEGIN
    -- 1. Update the stock
    UPDATE ingredients
    SET stock_qty = stock_qty + p_added_qty
    WHERE ingredient_id = p_ingredient_id;

    -- 2. Log the adjustment
    INSERT INTO stock_adjustments (item_id, item_type, user_id, adjustment_qty, reason)
    VALUES (p_ingredient_id, 'ingredient', p_user_id, p_added_qty, 'Restock');

    -- 3. Resolve alert (keep existing logic)
    UPDATE alerts
    SET status = 'resolved'
    WHERE ingredient_id = p_ingredient_id AND status = 'unread';
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `PosGetAvailableProducts` ()   BEGIN
    SELECT product_id, name, price, stock_qty
    FROM view_ProductInventory
    WHERE status = 'available' AND stock_qty > 0
    ORDER BY name;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `ProductAdd` (IN `name` VARCHAR(100), IN `price` DECIMAL(10,2))   BEGIN
    INSERT INTO products(name, price, stock_qty, status)
    VALUES (name, price, 0, 'available');

    SELECT LAST_INSERT_ID() AS new_product_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `ProductAdjustStock` (IN `p_product_id` INT, IN `p_user_id` INT, IN `p_adjustment_qty` INT, IN `p_reason` VARCHAR(255))   BEGIN
    -- Add validation block
    IF (p_adjustment_qty > 0 AND LOWER(p_reason) LIKE '%recall%') THEN
        -- Signal a custom error (This is the corrected syntax)
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Error: Recalls must have a negative quantity.';
    ELSE
        -- 1. Update the stock
        UPDATE products
        SET stock_qty = stock_qty + p_adjustment_qty
        WHERE product_id = p_product_id;

        -- 2. Log the adjustment
        INSERT INTO stock_adjustments (item_id, item_type, user_id, adjustment_qty, reason)
        VALUES (p_product_id, 'product', p_user_id, p_adjustment_qty, p_reason);
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `ProductGetById` (IN `p_product_id` INT)   BEGIN
    SELECT * FROM products WHERE product_id = p_product_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `ProductionRecordBaking` (IN `p_product_id` INT, IN `p_qty_baked` INT, OUT `p_status` VARCHAR(255))   BEGIN
    DECLARE v_batch_size INT;
    DECLARE v_num_batches FLOAT;
    DECLARE v_ingredient_name VARCHAR(100);
    DECLARE v_req_unit_base VARCHAR(20);
    DECLARE v_stock_unit_base VARCHAR(20);
    DECLARE v_error_unit_mismatch INT DEFAULT 0;
    DECLARE v_error_insufficient_stock INT DEFAULT 0;

    -- Get batch size
    SELECT batch_size INTO v_batch_size FROM products WHERE product_id = p_product_id;

    -- Prevent division by zero if batch_size is not set
    IF v_batch_size = 0 OR v_batch_size IS NULL THEN
        SET v_batch_size = 1;
    END IF;

    SET v_num_batches = p_qty_baked / v_batch_size;

    -- === 1. Check for Unit Compatibility ===
    -- Find if any ingredient has a recipe unit that cannot be converted to its stock unit
    SELECT COUNT(*), i.name INTO v_error_unit_mismatch, v_ingredient_name
    FROM recipes r
    JOIN ingredients i ON r.ingredient_id = i.ingredient_id
    LEFT JOIN unit_conversions uc_req ON r.unit = uc_req.unit
    LEFT JOIN unit_conversions uc_stock ON i.unit = uc_stock.unit
    WHERE r.product_id = p_product_id
    AND (uc_req.base_unit != uc_stock.base_unit OR uc_req.base_unit IS NULL OR uc_stock.base_unit IS NULL)
    LIMIT 1;

    IF v_error_unit_mismatch > 0 THEN
        SET p_status = CONCAT('Error: Unit mismatch for ', v_ingredient_name, '. Cannot convert recipe unit to stock unit.');
    ELSE
        -- === 2. Check for Insufficient Stock ===
        -- Find if any ingredient has insufficient stock, after converting both to base units
        SELECT COUNT(*), i.name INTO v_error_insufficient_stock, v_ingredient_name
        FROM recipes r
        JOIN ingredients i ON r.ingredient_id = i.ingredient_id
        JOIN unit_conversions uc_req ON r.unit = uc_req.unit
        JOIN unit_conversions uc_stock ON i.unit = uc_stock.unit
        WHERE r.product_id = p_product_id
        AND ( (r.qty_needed * v_num_batches * uc_req.to_base_factor) > (i.stock_qty * uc_stock.to_base_factor) )
        LIMIT 1;

        IF v_error_insufficient_stock > 0 THEN
            SET p_status = CONCAT('Error: Insufficient stock for ', v_ingredient_name, '.');
        ELSE
            -- === 3. Process Transaction ===
            -- All checks passed, proceed with deduction and logging
            START TRANSACTION;

            -- Deduct ingredients
            UPDATE ingredients i
            JOIN recipes r ON i.ingredient_id = r.ingredient_id
            JOIN unit_conversions uc_req ON r.unit = uc_req.unit
            JOIN unit_conversions uc_stock ON i.unit = uc_stock.unit
            SET
                i.stock_qty =
                -- Convert stock to base, subtract required in base, convert back to stock unit
                ( (i.stock_qty * uc_stock.to_base_factor) - (r.qty_needed * v_num_batches * uc_req.to_base_factor) ) / uc_stock.to_base_factor
            WHERE
                r.product_id = p_product_id;

            -- Add product stock
            UPDATE products
            SET stock_qty = stock_qty + p_qty_baked
            WHERE products.product_id = p_product_id;

            -- Log the production run
            INSERT INTO production (product_id, qty_baked, date)
            VALUES (p_product_id, p_qty_baked, CURDATE());

            COMMIT;

            SET p_status = 'Success: Production recorded.';

            -- Check for low stock alerts
            CALL IngredientCheckLowStock();
        END IF;
    END IF;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `ProductUpdate` (IN `p_product_id` INT, IN `p_name` VARCHAR(100), IN `p_price` DECIMAL(10,2), IN `p_status` ENUM('available','recalled','discontinued'))   BEGIN
    UPDATE products
    SET
        name = p_name,
        price = p_price,
        status = p_status
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `ReportGetBestSellers` (IN `date_start` DATE, IN `date_end` DATE)   BEGIN
    SELECT
        p.name,
        SUM(s.qty_sold) AS total_units_sold,
        SUM(s.total_price) AS total_revenue
    FROM sales s
    JOIN products p ON s.product_id = p.product_id
    WHERE s.date BETWEEN date_start AND date_end
    GROUP BY p.product_id, p.name
    ORDER BY total_units_sold DESC;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `ReportGetSalesHistory` (IN `p_date_start` DATE, IN `p_date_end` DATE, IN `p_sort_column` VARCHAR(50), IN `p_sort_direction` VARCHAR(4))   BEGIN
    -- Whitelist direction
    SET @order_dir_asc = (UPPER(p_sort_direction) = 'ASC');

    SELECT
        s.date,
        p.name AS product_name,
        s.qty_sold,
        s.total_price, -- Uses the price stored in the sales table
        u.username AS cashier_username
    FROM
        sales s
    LEFT JOIN
        products p ON s.product_id = p.product_id
    LEFT JOIN
        users u ON s.user_id = u.user_id
    WHERE
        s.date BETWEEN p_date_start AND p_date_end
    ORDER BY
        -- Text Columns
        CASE WHEN p_sort_column = 'product' AND @order_dir_asc THEN p.name END ASC,
        CASE WHEN p_sort_column = 'product' AND NOT @order_dir_asc THEN p.name END DESC,
        CASE WHEN p_sort_column = 'cashier' AND @order_dir_asc THEN u.username END ASC,
        CASE WHEN p_sort_column = 'cashier' AND NOT @order_dir_asc THEN u.username END DESC,

        -- Numeric Columns
        CASE WHEN p_sort_column = 'qty' AND @order_dir_asc THEN s.qty_sold END ASC,
        CASE WHEN p_sort_column = 'qty' AND NOT @order_dir_asc THEN s.qty_sold END DESC,
        CASE WHEN p_sort_column = 'price' AND @order_dir_asc THEN s.total_price END ASC,
        CASE WHEN p_sort_column = 'price' AND NOT @order_dir_asc THEN s.total_price END DESC,

        -- Date Column (Default)
        CASE WHEN p_sort_column = 'date' AND @order_dir_asc THEN s.date END ASC,
        CASE WHEN p_sort_column = 'date' AND NOT @order_dir_asc THEN s.date END DESC,

        -- Default fallback sort
        s.date DESC, s.sale_id DESC; -- Added sale_id for stable sorting
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `ReportGetSalesSummaryByDate` (IN `p_date_start` DATE, IN `p_date_end` DATE)   BEGIN
    SELECT 
        p.name AS product_name,
        SUM(s.qty_sold) AS total_qty_sold,
        SUM(s.total_price) AS total_revenue
    FROM 
        sales s
    JOIN 
        products p ON s.product_id = p.product_id
    WHERE 
        s.date BETWEEN p_date_start AND p_date_end
    GROUP BY 
        p.product_id, p.name
    ORDER BY 
        total_qty_sold DESC;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `ReportGetSalesSummaryToday` ()   BEGIN
    SELECT 
        COUNT(sale_id) AS totalSales,
        SUM(total_price) AS totalRevenue
    FROM 
        sales
    WHERE 
        date = CURDATE();
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `SaleRecordTransaction` (IN `user_id` INT, IN `product_id` INT, IN `qty_sold` INT, OUT `status` VARCHAR(100), OUT `sale_id` INT)   BEGIN
    DECLARE current_stock INT;
    DECLARE product_price DECIMAL(10,2);
    DECLARE product_status ENUM('available', 'recalled', 'discontinued');
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

        -- 1. Calculate total
        SET total_price = product_price * qty_sold;

        -- 2. Deduct from product stock
        UPDATE products
        SET stock_qty = stock_qty - qty_sold
        WHERE products.product_id = product_id;

        -- 3. Record the sale
        INSERT INTO sales (product_id, user_id, qty_sold, total_price, date)
        VALUES (product_id, user_id, qty_sold, total_price, CURDATE());

        SET sale_id = LAST_INSERT_ID();
        SET status = 'Success: Sale recorded.';

        COMMIT;
    END IF;

END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `UserCreateAccount` (IN `p_username` VARCHAR(100), IN `p_hashed_password` VARCHAR(255), IN `p_role` ENUM('manager','cashier'), IN `p_email` VARCHAR(150), IN `p_phone_number` VARCHAR(11))   BEGIN
    INSERT INTO users (username, password, role, email, phone_number)
    VALUES (p_username, p_hashed_password, p_role, p_email, p_phone_number);
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `UserFindById` (IN `p_user_id` INT)   BEGIN
    SELECT user_id, username, role, email, phone_number FROM users WHERE user_id = p_user_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `UserFindByPhone` (IN `p_phone_number` VARCHAR(11))   BEGIN
    SELECT * FROM users WHERE phone_number = p_phone_number;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `UserLogin` (IN `p_username` VARCHAR(100))   BEGIN
    -- This LOWER() function fixes the case-sensitive login (e.g., Camile123 vs camile123)
    SELECT * FROM users WHERE LOWER(username) = LOWER(p_username);
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
(3, 'Flour', 'kg', 25, 5),
(4, 'Yeast', 'kg', 20, 5),
(5, 'Egg', 'tray', 15, 5);

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
(11, 3, 'phone_otp', NULL, '392128', '2025-10-30 22:57:37', 1);

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

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `product_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `status` enum('available','recalled','discontinued') NOT NULL DEFAULT 'available',
  `stock_qty` int(11) DEFAULT 0,
  `stock_unit` varchar(20) NOT NULL DEFAULT 'pcs',
  `is_sellable` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = Appears on POS, 0 = Intermediate product'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`product_id`, `name`, `price`, `status`, `stock_qty`, `stock_unit`, `is_sellable`) VALUES
(1, 'Spanish Bread', 10.00, 'available', 50, 'pcs', 1),
(2, 'Cheese Bread', 12.00, 'available', 30, 'pcs', 1),
(3, 'Ensaymada', 15.00, 'available', 28, 'pcs', 1),
(4, 'Cinnamon Roll', 20.00, 'available', 25, 'pcs', 1),
(5, 'Choco Bread', 12.00, 'available', 35, 'pcs', 1),
(6, 'Ube Cheese Pandesal', 15.00, 'available', 35, 'pcs', 1),
(7, 'Hotdog Roll', 18.00, 'available', 15, 'pcs', 1),
(8, 'Cheese Stick Bread', 10.00, 'available', 30, 'pcs', 1),
(9, 'Tuna Bun', 20.00, 'available', 15, 'pcs', 1),
(10, 'Egg Pie Slice', 25.00, 'available', 8, 'pcs', 1),
(11, 'Mocha Bun', 12.00, 'available', 35, 'pcs', 1),
(12, 'Corned Beef Bread', 20.00, 'available', 20, 'pcs', 1),
(13, 'Chicken Floss Bun', 25.00, 'available', 40, 'pcs', 1),
(14, 'Chocolate Donut', 18.00, 'available', 30, 'pcs', 1),
(16, 'Cream Bread', 10.00, 'available', 48, 'pcs', 1),
(17, 'Coffee Bun', 15.00, 'available', 30, 'pcs', 1),
(18, 'Garlic Bread', 12.00, 'available', 30, 'pcs', 1),
(19, 'Milky Loaf', 35.00, 'available', 19, 'loaf', 1),
(20, 'Whole Wheat Loaf', 40.00, 'available', 14, 'loaf', 1),
(21, 'Raisin Bread', 20.00, 'available', 15, 'pcs', 1),
(22, 'Banana Loaf', 35.00, 'available', 10, 'loaf', 1),
(23, 'Cheese Cupcake', 15.00, 'available', 25, 'pcs', 1),
(24, 'Butter Muffin', 18.00, 'available', 20, 'pcs', 1),
(25, 'Yema Bread', 12.00, 'available', 30, 'pcs', 1),
(26, 'Chocolate Crinkles', 10.00, 'available', 25, 'pcs', 1),
(27, 'Pan de Coco', 12.00, 'available', 20, 'pcs', 1),
(28, 'Baguette', 30.00, 'available', 10, 'pcs', 1),
(29, 'Focaccia Bread', 28.00, 'available', 5, 'pcs', 1),
(30, 'Mini Donut', 8.00, 'available', 30, 'pcs', 1);

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
  `date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`sale_id`, `product_id`, `user_id`, `qty_sold`, `total_price`, `date`) VALUES
(1, 7, 3, 2, 50.00, '2025-10-23'),
(2, 4, 3, 10, 50.00, '2025-10-23'),
(3, 4, 3, 5, 25.00, '2025-10-23'),
(4, 6, 3, 3, 75.00, '2025-10-23'),
(5, 4, 3, 5, 25.00, '2025-10-23'),
(6, 6, 3, 4, 100.00, '2025-10-23'),
(7, 7, 3, 5, 125.00, '2025-10-23'),
(8, 7, 3, 2, 50.00, '2025-10-23'),
(9, 6, 3, 1, 25.00, '2025-10-23'),
(10, 4, 3, 5, 25.00, '2025-10-23'),
(11, 7, 3, 4, 100.00, '2025-10-23'),
(12, 4, 3, 10, 50.00, '2025-10-23'),
(13, 6, 3, 2, 50.00, '2025-10-23'),
(14, 7, 3, 2, 50.00, '2025-10-23'),
(15, 4, 3, 10, 50.00, '2025-10-28'),
(16, 6, 3, 2, 50.00, '2025-10-28'),
(17, 7, 3, 2, 50.00, '2025-10-28'),
(18, 4, 3, 10, 50.00, '2025-10-28'),
(19, 4, 3, 5, 25.00, '2025-10-28'),
(20, 6, 3, 3, 75.00, '2025-10-28'),
(21, 7, 3, 2, 50.00, '2025-10-28'),
(22, 6, 3, 2, 50.00, '2025-10-28'),
(23, 7, 3, 2, 50.00, '2025-10-28'),
(24, 7, 3, 5, 125.00, '2025-10-28'),
(25, 7, 3, 2, 50.00, '2025-10-28'),
(26, 6, 3, 1, 25.00, '2025-10-28'),
(27, 4, 3, 10, 50.00, '2025-10-28'),
(28, 4, 3, 5, 25.00, '2025-10-28'),
(29, 6, 3, 2, 50.00, '2025-10-28'),
(30, 7, 3, 2, 50.00, '2025-10-28'),
(31, 4, 3, 10, 50.00, '2025-10-30'),
(32, 13, 3, 2, 50.00, '2025-10-30'),
(33, 10, 3, 2, 50.00, '2025-10-30'),
(34, 3, 3, 2, 30.00, '2025-10-30'),
(35, 7, 3, 5, 90.00, '2025-10-30'),
(36, 16, 3, 2, 20.00, '2025-10-30'),
(37, 19, 3, 1, 35.00, '2025-10-30'),
(38, 20, 3, 1, 40.00, '2025-10-30'),
(39, 6, 3, 5, 75.00, '2025-10-30'),
(40, 13, 3, 3, 75.00, '2025-11-04');

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
(45, 27, 'product', 3, -10, 'recall Spoilage', '2025-11-04 09:43:34');

-- --------------------------------------------------------

--
-- Table structure for table `unit_conversions`
--

CREATE TABLE `unit_conversions` (
  `unit` varchar(20) NOT NULL,
  `base_unit` enum('g','ml','pcs') NOT NULL,
  `to_base_factor` float NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('manager','cashier') NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone_number` varchar(11) DEFAULT NULL,
  `enable_daily_report` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'For daily SMS reports',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password`, `role`, `email`, `phone_number`, `enable_daily_report`, `created_at`) VALUES
(1, 'camile123', '$2y$10$Dfv1I9ZXClQUsKS5SOTRP.UrjdaHjcRHLT7lzV0JrZbvxbVkehmKy', 'manager', 'camile@gmail.com', '09935581868', 0, '2025-10-20 04:44:55'),
(2, 'klain123', '$2y$10$pS2IpgUKXqAaSGO3oqgSHOnVJ0CS3FHy6f0nrDxFj6iapGe3FeTne', 'cashier', 'klain@gmail.com', '09923142756', 0, '2025-10-20 04:44:55'),
(3, 'gian123', '$2y$10$/FDRF1Ki3yrVAWlxtAdnYusYiz6xD4bujgsyv59LA6cya713Gk.CO', 'manager', 'givano550@gmail.com', '09359840820', 0, '2025-10-20 05:50:57');

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
,`stock_qty` float
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

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_ingredientstocklevel`  AS SELECT `ingredients`.`ingredient_id` AS `ingredient_id`, `ingredients`.`name` AS `name`, `ingredients`.`unit` AS `unit`, `ingredients`.`stock_qty` AS `stock_qty`, `ingredients`.`reorder_level` AS `reorder_level`, `ingredients`.`stock_qty`- `ingredients`.`reorder_level` AS `stock_surplus` FROM `ingredients` ;

-- --------------------------------------------------------

--
-- Structure for view `view_productinventory`
--
DROP TABLE IF EXISTS `view_productinventory`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_productinventory`  AS SELECT `products`.`product_id` AS `product_id`, `products`.`name` AS `name`, `products`.`price` AS `price`, `products`.`stock_qty` AS `stock_qty`, `products`.`status` AS `status`, `products`.`stock_unit` AS `stock_unit`, `products`.`is_sellable` AS `is_sellable` FROM `products` WHERE `products`.`status` in ('available','recalled') ;

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
  ADD PRIMARY KEY (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `alerts`
--
ALTER TABLE `alerts`
  MODIFY `alert_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ingredients`
--
ALTER TABLE `ingredients`
  MODIFY `ingredient_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `reset_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `production`
--
ALTER TABLE `production`
  MODIFY `production_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

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
  MODIFY `recipe_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `sale_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `stock_adjustments`
--
ALTER TABLE `stock_adjustments`
  MODIFY `adjustment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `stock_adjustments`
--
ALTER TABLE `stock_adjustments`
  ADD CONSTRAINT `fk_stock_adjustments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
