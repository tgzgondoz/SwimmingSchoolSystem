-- SQL dump (schema only). Run install.php to populate demo data automatically.
CREATE DATABASE IF NOT EXISTS `swimming_school` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `swimming_school`;

CREATE TABLE IF NOT EXISTS users (
id INT AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(100) NOT NULL,
email VARCHAR(100) NOT NULL UNIQUE,
password VARCHAR(255) NOT NULL,
role ENUM('admin','student') NOT NULL DEFAULT 'student',
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS instructors (
id INT AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(100),
email VARCHAR(100),
phone VARCHAR(20),
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS classes (
id INT AUTO_INCREMENT PRIMARY KEY,
title VARCHAR(150),
instructor_id INT,
age_group VARCHAR(50),
start_time DATETIME,
end_time DATETIME,
slots_total INT,
slots_available INT,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (instructor_id) REFERENCES instructors(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bookings (
id INT AUTO_INCREMENT PRIMARY KEY,
user_id INT,
class_id INT,
status ENUM('confirmed','pending','cancelled') DEFAULT 'pending',
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
id INT AUTO_INCREMENT PRIMARY KEY,
user_id INT,
amount DECIMAL(10,2),
payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
status ENUM('paid','pending') DEFAULT 'pending',
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
