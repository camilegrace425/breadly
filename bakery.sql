-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 20, 2025 at 04:13 PM
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `IngredientRestock` (IN `ingredient_id` INT, IN `added_qty` FLOAT)   BEGIN
    UPDATE ingredients
    SET ingredients.stock_qty = ingredients.stock_qty + added_qty
    WHERE ingredients.ingredient_id = ingredient_id;

    -- Optional: If it was out of stock, resolve the alert
    UPDATE alerts
    SET alerts.status = 'resolved'
    WHERE alerts.ingredient_id = ingredient_id AND alerts.status = 'unread';
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `ProductionRecordBaking` (IN `product_id` INT, IN `qty_baked` INT, OUT `status` VARCHAR(100))   BEGIN
    DECLARE ingredient_count INT;
    DECLARE insufficient_stock INT;
    
    -- Check for sufficient ingredients BEFORE starting transaction
    SELECT COUNT(*) INTO insufficient_stock
    FROM recipes r
    JOIN ingredients i ON r.ingredient_id = i.ingredient_id
    WHERE r.product_id = product_id
      AND i.stock_qty < (r.qty_needed * qty_baked);

    IF insufficient_stock > 0 THEN
        SET status = 'Error: Insufficient ingredient stock.';
    ELSE
        START TRANSACTION;
        
        -- 1. Deduct ingredients
        UPDATE ingredients i
        JOIN recipes r ON i.ingredient_id = r.ingredient_id
        SET 
            i.stock_qty = i.stock_qty - (r.qty_needed * qty_baked)
        WHERE 
            r.product_id = product_id;
            
        -- 2. Add to product stock
        UPDATE products
        SET stock_qty = stock_qty + qty_baked
        WHERE products.product_id = product_id;
        
        -- 3. Log the production run
        INSERT INTO production (product_id, qty_baked, date)
        VALUES (product_id, qty_baked, CURDATE());
        
        COMMIT;
        SET status = 'Success: Production recorded.';
        
        -- 4. After success, check if we triggered any low stock
        CALL IngredientCheckLowStock();
    END IF;
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
        i.unit
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `ReportGetSalesByCashier` (IN `date_start` DATE, IN `date_end` DATE)   BEGIN
    SELECT 
        u.username,
        u.role,
        COUNT(s.sale_id) AS total_transactions,
        SUM(s.total_price) AS total_revenue
    FROM sales s
    JOIN users u ON s.user_id = u.user_id
    WHERE s.date BETWEEN date_start AND date_end
    GROUP BY u.user_id, u.username, u.role
    ORDER BY total_revenue DESC;
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `UserLogin` (IN `username` VARCHAR(100), IN `password` VARCHAR(255))   BEGIN
    SELECT 
        user_id, 
        users.username, 
        role, 
        email
    FROM users
    WHERE users.username = username AND users.password = password;
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
(1, 1, 'email_token', '6ee4f224-adbb-11f0-b2b8-c01850aa0dfb', NULL, '2025-10-20 22:48:17', 0),
(2, 3, 'email_token', 'd1540930-adbb-11f0-b2b8-c01850aa0dfb', NULL, '2025-10-20 22:51:03', 0),
(3, 3, 'email_token', 'dfecc026-adbb-11f0-b2b8-c01850aa0dfb', NULL, '2025-10-20 22:51:27', 0);

-- --------------------------------------------------------

--
-- Table structure for table `production`
--

CREATE TABLE `production` (
  `production_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `qty_baked` int(11) DEFAULT NULL,
  `date` date DEFAULT NULL
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
  `stock_qty` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `product_id` int(11) DEFAULT NULL,
  `ingredient_id` int(11) DEFAULT NULL,
  `qty_needed` float DEFAULT NULL
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
(1, 'camile123', '$2y$10$Dfv1I9ZXClQUsKS5SOTRP.UrjdaHjcRHLT7lzV0JrZbvxbVkehmKy', 'manager', 'camile@gmail.com', '09123456789', '2025-10-20 12:44:55'),
(2, 'klain123', '$2y$10$WnuNoGX/HOAm2ykTqzKWPONBVYztP5XwWkXbsJGiy29PMWtHKPJSe', 'cashier', 'klain@gmail.com', '09987654321', '2025-10-20 12:44:55'),
(3, 'gian123', '$2y$10$Dfv1I9ZXClQUsKS5SOTRP.UrjdaHjcRHLT7lzV0JrZbvxbVkehmKy', 'manager', 'givano550@gmail.com', NULL, '2025-10-20 13:50:57');

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
-- Stand-in structure for view `view_activerecalls`
-- (See below for the actual view)
--
CREATE TABLE `view_activerecalls` (
`recall_id` int(11)
,`recall_date` date
,`product_id` int(11)
,`product_name` varchar(100)
,`reason` text
,`affected_batch_date_start` date
,`affected_batch_date_end` date
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_fullrecipedetails`
-- (See below for the actual view)
--
CREATE TABLE `view_fullrecipedetails` (
`product_id` int(11)
,`product_name` varchar(100)
,`ingredient_id` int(11)
,`ingredient_name` varchar(100)
,`qty_needed` float
,`unit` varchar(50)
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
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_productionhistory`
-- (See below for the actual view)
--
CREATE TABLE `view_productionhistory` (
`production_id` int(11)
,`date` date
,`product_name` varchar(100)
,`qty_baked` int(11)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_salesdetails`
-- (See below for the actual view)
--
CREATE TABLE `view_salesdetails` (
`sale_id` int(11)
,`date` date
,`product_name` varchar(100)
,`qty_sold` int(11)
,`total_price` decimal(10,2)
,`cashier_name` varchar(100)
,`role` enum('manager','cashier')
);

-- --------------------------------------------------------

--
-- Structure for view `view_activelowstockalerts`
--
DROP TABLE IF EXISTS `view_activelowstockalerts`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_activelowstockalerts`  AS SELECT `a`.`alert_id` AS `alert_id`, `a`.`ingredient_id` AS `ingredient_id`, `i`.`name` AS `ingredient_name`, `i`.`stock_qty` AS `current_stock`, `i`.`reorder_level` AS `reorder_level`, `a`.`message` AS `message`, `a`.`date_triggered` AS `date_triggered` FROM (`alerts` `a` join `ingredients` `i` on(`a`.`ingredient_id` = `i`.`ingredient_id`)) WHERE `a`.`status` = 'unread' ;

-- --------------------------------------------------------

--
-- Structure for view `view_activerecalls`
--
DROP TABLE IF EXISTS `view_activerecalls`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_activerecalls`  AS SELECT `pr`.`recall_id` AS `recall_id`, `pr`.`recall_date` AS `recall_date`, `p`.`product_id` AS `product_id`, `p`.`name` AS `product_name`, `pr`.`reason` AS `reason`, `pr`.`affected_batch_date_start` AS `affected_batch_date_start`, `pr`.`affected_batch_date_end` AS `affected_batch_date_end` FROM (`product_recalls` `pr` join `products` `p` on(`pr`.`product_id` = `p`.`product_id`)) WHERE `pr`.`status` = 'active' ;

-- --------------------------------------------------------

--
-- Structure for view `view_fullrecipedetails`
--
DROP TABLE IF EXISTS `view_fullrecipedetails`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_fullrecipedetails`  AS SELECT `p`.`product_id` AS `product_id`, `p`.`name` AS `product_name`, `i`.`ingredient_id` AS `ingredient_id`, `i`.`name` AS `ingredient_name`, `r`.`qty_needed` AS `qty_needed`, `i`.`unit` AS `unit` FROM ((`recipes` `r` join `products` `p` on(`r`.`product_id` = `p`.`product_id`)) join `ingredients` `i` on(`r`.`ingredient_id` = `i`.`ingredient_id`)) ORDER BY `p`.`name` ASC, `i`.`name` ASC ;

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

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_productinventory`  AS SELECT `products`.`product_id` AS `product_id`, `products`.`name` AS `name`, `products`.`price` AS `price`, `products`.`stock_qty` AS `stock_qty`, `products`.`status` AS `status` FROM `products` WHERE `products`.`status` in ('available','recalled') ;

-- --------------------------------------------------------

--
-- Structure for view `view_productionhistory`
--
DROP TABLE IF EXISTS `view_productionhistory`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_productionhistory`  AS SELECT `pr`.`production_id` AS `production_id`, `pr`.`date` AS `date`, `p`.`name` AS `product_name`, `pr`.`qty_baked` AS `qty_baked` FROM (`production` `pr` join `products` `p` on(`pr`.`product_id` = `p`.`product_id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `view_salesdetails`
--
DROP TABLE IF EXISTS `view_salesdetails`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_salesdetails`  AS SELECT `s`.`sale_id` AS `sale_id`, `s`.`date` AS `date`, `p`.`name` AS `product_name`, `s`.`qty_sold` AS `qty_sold`, `s`.`total_price` AS `total_price`, `u`.`username` AS `cashier_name`, `u`.`role` AS `role` FROM ((`sales` `s` join `products` `p` on(`s`.`product_id` = `p`.`product_id`)) join `users` `u` on(`s`.`user_id` = `u`.`user_id`)) ;

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
  ADD PRIMARY KEY (`reset_id`),
  ADD KEY `user_id` (`user_id`);

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
  ADD PRIMARY KEY (`sale_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `phone_number` (`phone_number`);

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
  MODIFY `ingredient_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `reset_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `production`
--
ALTER TABLE `production`
  MODIFY `production_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT;

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
  MODIFY `sale_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `alerts`
--
ALTER TABLE `alerts`
  ADD CONSTRAINT `alerts_ibfk_1` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`ingredient_id`);

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `production`
--
ALTER TABLE `production`
  ADD CONSTRAINT `production_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`);

--
-- Constraints for table `product_recalls`
--
ALTER TABLE `product_recalls`
  ADD CONSTRAINT `product_recalls_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`);

--
-- Constraints for table `recalled_stock_log`
--
ALTER TABLE `recalled_stock_log`
  ADD CONSTRAINT `recalled_stock_log_ibfk_1` FOREIGN KEY (`recall_id`) REFERENCES `product_recalls` (`recall_id`),
  ADD CONSTRAINT `recalled_stock_log_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `recipes`
--
ALTER TABLE `recipes`
  ADD CONSTRAINT `recipes_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  ADD CONSTRAINT `recipes_ibfk_2` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`ingredient_id`);

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  ADD CONSTRAINT `sales_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
