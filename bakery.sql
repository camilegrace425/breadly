-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 22, 2025 at 10:03 PM
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
CREATE DEFINER=`root`@`localhost` PROCEDURE `AlertMarkResolved` (IN `alert_id` INT)   BEGIN
    UPDATE alerts
    SET status = 'resolved'
    WHERE alerts.alert_id = alert_id;
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `IngredientRestock` (IN `ingredient_id` INT, IN `added_qty` FLOAT)   BEGIN
    UPDATE ingredients
    SET ingredients.stock_qty = ingredients.stock_qty + added_qty
    WHERE ingredients.ingredient_id = ingredient_id;

    -- Optional: If it was out of stock, resolve the alert
    UPDATE alerts
    SET alerts.status = 'resolved'
    WHERE alerts.ingredient_id = ingredient_id AND alerts.status = 'unread';
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `ProductAdd` (IN `name` VARCHAR(100), IN `price` DECIMAL(10,2))   BEGIN
    INSERT INTO products(name, price, stock_qty, status)
    VALUES (name, price, 0, 'available');
    
    SELECT LAST_INSERT_ID() AS new_product_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `ProductAdjustStock` (IN `product_id` INT, IN `adjustment_qty` INT, IN `reason` VARCHAR(255))   BEGIN
    UPDATE products
    SET stock_qty = stock_qty + adjustment_qty
    WHERE products.product_id = product_id;
    
    -- NOTE: You might want a separate 'stock_adjustments' log table
    -- to record reason and who made the change.
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `RecipeAddIngredient` (IN `product_id` INT, IN `ingredient_id` INT, IN `qty_needed` FLOAT)   BEGIN
    INSERT INTO recipes(product_id, ingredient_id, qty_needed)
    VALUES (product_id, ingredient_id, qty_needed);
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `RecipeGetForProduct` (IN `product_id` INT)   BEGIN
    SELECT 
        r.recipe_id,
        i.ingredient_id,
        i.name,
        r.qty_needed,
        r.unit -- <-- ADDED
    FROM recipes r
    JOIN ingredients i ON r.ingredient_id = i.ingredient_id
    WHERE r.product_id = product_id;
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `ReportGetSalesHistory` (IN `p_date_start` DATE, IN `p_date_end` DATE)   BEGIN
    SELECT 
        s.date, -- This was the main error (was s.sale_timestamp)
        p.name AS product_name,
        s.qty_sold,
        (s.qty_sold * p.price) AS total_price,
        u.username AS cashier_username
    FROM 
        sales s
    LEFT JOIN 
        products p ON s.product_id = p.product_id
    LEFT JOIN 
        users u ON s.user_id = u.user_id
    WHERE 
        s.date BETWEEN p_date_start AND p_date_end -- Simplified this
    ORDER BY 
        s.date DESC; -- Also changed this
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

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `alerts`
--

CREATE TABLE `alerts` (
  `alert_id` int(11) NOT NULL,
  `ingredient_id` int(11) DEFAULT NULL,
  `message` varchar(255) DEFAULT NULL,
  `date_triggered` date DEFAULT NULL,
  `status` enum('unread','resolved') DEFAULT 'unread'
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
(15, 'Montana', 'kg', 115.667, 25),
(16, 'Wheat Flour', 'kg', 25, 10),
(17, 'Primavera', 'kg', 10, 2),
(18, 'All-purpose flour', 'kg', 15, 3),
(19, 'Bisugo', 'kg', 10, 2),
(20, 'Manalo', 'kg', 10, 2),
(21, 'Washington', 'kg', 10, 2),
(22, 'Lulista', 'kg', 20, 4),
(23, 'Washed sugar', 'kg', 10, 2),
(24, 'White Sugar', 'kg', 19.8667, 4),
(25, 'Powdered Milk', 'kg', 9.73333, 1),
(26, 'Dairy Crème', 'pcs', 2.33333, 1),
(27, 'Jersey Condensed', 'can', 12, 3),
(28, 'Lard (animal)', 'kg', 5, 1),
(29, 'Lard (veg shortening)', 'kg', 10, 2),
(30, 'Margarine', 'kg', 10, 2),
(31, 'Butter', 'kg', 5, 1),
(32, 'Fried oil / Sunflower oil', 'L', 10, 2),
(33, 'SAF', 'kg', 4.98667, 1),
(34, 'Baking Powder', 'kg', 5, 1),
(35, 'Anti-Amag', 'pack', 2, 0.5),
(36, 'Cream of Tartar', 'g', 500, 100),
(37, 'Baking Soda', 'pack', 5, 1),
(38, 'Vanilla GAL', 'bottle', 2, 0.5),
(39, 'Cocoa', 'kg', 2, 0.5),
(40, 'Desiccated Coconut', 'kg', 3, 1),
(41, 'Chicken Floss', 'pack', 5, 1),
(42, 'Garlic', 'kg', 1, 0.2),
(43, 'Parsley Flakes', 'bottle', 1, 0.2),
(44, 'Basil Flakes', 'bottle', 1, 0.2),
(45, 'Petoleco', 'bottle', 3, 1),
(46, 'Cornstarch', 'kg', 5, 1),
(47, 'Cassava Starch', 'kg', 5, 1),
(48, 'Donut Glaze (Colatta)', 'pack', 5, 1),
(49, 'Donut Glaze (Elements)', 'pack', 5, 1),
(50, 'Jersey Cheese', 'pack', 10, 3),
(51, 'Eggs', 'pcs', 90.6667, 30);

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

-- --------------------------------------------------------

--
-- Table structure for table `production`
--

CREATE TABLE `production` (
  `production_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `qty_baked` int(11) DEFAULT NULL,
  `date` date DEFAULT NULL,
  `batch_size_produced` int(11) DEFAULT 1
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
  `batch_size` int(11) DEFAULT 1,
  `stock_unit` varchar(20) NOT NULL DEFAULT 'pcs',
  `is_sellable` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = Appears on POS, 0 = Intermediate product'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`product_id`, `name`, `price`, `status`, `stock_qty`, `batch_size`, `stock_unit`, `is_sellable`) VALUES
(4, 'Jumbo Pandesal', 5.00, 'available', 0, 150, 'pcs', 1),
(6, 'Ham & Cheese', 25.00, 'available', 0, 1, 'pcs', 1),
(7, 'Garlic Cheese', 25.00, 'available', 0, 1, 'pcs', 1);

-- --------------------------------------------------------

--
-- Table structure for table `product_recalls`
--

CREATE TABLE `product_recalls` (
  `recall_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `reason` text NOT NULL,
  `recall_date` date NOT NULL,
  `status` enum('active','completed') DEFAULT 'active',
  `affected_batch_date_start` date DEFAULT NULL,
  `affected_batch_date_end` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `recalled_stock_log`
--

CREATE TABLE `recalled_stock_log` (
  `log_id` int(11) NOT NULL,
  `recall_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `qty_removed` int(11) NOT NULL,
  `date_removed` datetime DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `recipes`
--

CREATE TABLE `recipes` (
  `recipe_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL COMMENT 'The product this recipe is FOR',
  `ingredient_id` int(11) DEFAULT NULL COMMENT 'Link to raw ingredients',
  `sub_product_id` int(11) DEFAULT NULL COMMENT 'Link to another product (sub-assembly)',
  `qty_needed` float NOT NULL,
  `unit` varchar(20) NOT NULL
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
(0, 4, 3, 10, 50.00, '2025-10-22'),
(0, 7, 3, 1, 25.00, '2025-10-23'),
(0, 7, 3, 12, 300.00, '2025-10-23');

-- --------------------------------------------------------

--
-- Table structure for table `unit_conversions`
--

CREATE TABLE `unit_conversions` (
  `id` int(11) NOT NULL,
  `from_unit` varchar(20) NOT NULL,
  `to_unit` varchar(20) NOT NULL,
  `conversion_factor` float NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `unit_conversions`
--

INSERT INTO `unit_conversions` (`id`, `from_unit`, `to_unit`, `conversion_factor`) VALUES
(1, 'kg', 'g', 1000),
(2, 'g', 'kg', 0.001),
(3, 'L', 'ml', 1000),
(4, 'ml', 'L', 0.001),
(5, 'pcs', 'pcs', 1),
(6, 'pack', 'pack', 1),
(7, 'tray', 'tray', 1),
(8, 'can', 'can', 1),
(9, 'bottle', 'bottle', 1),
(10, 'tbsp', 'g', 15),
(11, 'g', 'tbsp', 0.0667),
(12, 'kg', 'pcs', 1),
(13, 'g', 'pcs', 1),
(14, 'L', 'pcs', 1),
(15, 'ml', 'pcs', 1),
(16, 'pcs', 'g', 1),
(17, 'pcs', 'kg', 1),
(18, 'pcs', 'ml', 1),
(19, 'pcs', 'L', 1),
(20, 'g', 'ml', 1),
(21, 'ml', 'g', 1),
(22, 'kg', 'ml', 1000),
(23, 'ml', 'kg', 0.001);

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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password`, `role`, `email`, `phone_number`, `created_at`) VALUES
(1, 'camile123', '$2y$10$Dfv1I9ZXClQUsKS5SOTRP.UrjdaHjcRHLT7lzV0JrZbvxbVkehmKy', 'manager', 'camile@gmail.com', '09123456789', '2025-10-20 04:44:55'),
(2, 'klain123', '$2y$10$WnuNoGX/HOAm2ykTqzKWPONBVYztP5XwWkXbsJGiy29PMWtHKPJSe', 'cashier', 'klain@gmail.com', '09987654321', '2025-10-20 04:44:55'),
(3, 'gian123', '$2y$10$Dfv1I9ZXClQUsKS5SOTRP.UrjdaHjcRHLT7lzV0JrZbvxbVkehmKy', 'manager', 'givano550@gmail.com', NULL, '2025-10-20 05:50:57');

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
,`message` varchar(255)
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
,`batch_size` int(11)
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
,`batch_size` int(11)
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

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_discontinuedproducts`  AS SELECT `products`.`product_id` AS `product_id`, `products`.`name` AS `name`, `products`.`price` AS `price`, `products`.`stock_qty` AS `stock_qty`, `products`.`status` AS `status`, `products`.`batch_size` AS `batch_size`, `products`.`stock_unit` AS `stock_unit`, `products`.`is_sellable` AS `is_sellable` FROM `products` WHERE `products`.`status` = 'discontinued' ORDER BY `products`.`name` ASC ;

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

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_productinventory`  AS SELECT `products`.`product_id` AS `product_id`, `products`.`name` AS `name`, `products`.`price` AS `price`, `products`.`stock_qty` AS `stock_qty`, `products`.`status` AS `status`, `products`.`batch_size` AS `batch_size`, `products`.`stock_unit` AS `stock_unit`, `products`.`is_sellable` AS `is_sellable` FROM `products` WHERE `products`.`status` in ('available','recalled') ;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
