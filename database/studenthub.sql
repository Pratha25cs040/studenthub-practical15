-- =====================================================================
-- StudentHub Practical 15: Final Integrated Student Management Portal
-- Database Schema and Sample Dataset
-- Compatible with MySQL 5.7+ / MySQL 8.0+ / MariaDB 10.4+ on XAMPP
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `studenthub` 
  CHARACTER SET utf8mb4 
  COLLATE utf8mb4_unicode_ci;

USE `studenthub`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `registrations`;
DROP TABLE IF EXISTS `events`;
DROP TABLE IF EXISTS `students`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Table: users (Authentication and Role Management)
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
  `user_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `email` VARCHAR(254) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('student', 'admin') NOT NULL DEFAULT 'student',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_users_role` (`role`),
  INDEX `idx_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: students (Academic Student Profiles & Records)
-- ---------------------------------------------------------------------
CREATE TABLE `students` (
  `student_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL UNIQUE,
  `full_name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(254) NOT NULL UNIQUE,
  `department` VARCHAR(100) NOT NULL,
  `semester` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `phone` VARCHAR(20) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_students_department` (`department`),
  INDEX `idx_students_semester` (`semester`),
  CONSTRAINT `fk_student_user` FOREIGN KEY (`user_id`) 
    REFERENCES `users` (`user_id`) 
    ON DELETE SET NULL 
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: events (Campus Events & Workshops)
-- ---------------------------------------------------------------------
CREATE TABLE `events` (
  `event_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_name` VARCHAR(150) NOT NULL,
  `category` VARCHAR(60) NOT NULL DEFAULT 'Academic',
  `event_date` DATE NOT NULL,
  `venue` VARCHAR(150) NOT NULL,
  `max_seats` INT UNSIGNED NOT NULL DEFAULT 60,
  `description` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_events_date` (`event_date`),
  INDEX `idx_events_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: registrations (Event Enrollment with Relational Integrity)
-- ---------------------------------------------------------------------
CREATE TABLE `registrations` (
  `registration_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT UNSIGNED NOT NULL,
  `event_id` INT UNSIGNED NOT NULL,
  `status` ENUM('confirmed', 'waitlisted', 'cancelled') NOT NULL DEFAULT 'confirmed',
  `registration_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_student_event` (`student_id`, `event_id`),
  INDEX `idx_reg_student` (`student_id`),
  INDEX `idx_reg_event` (`event_id`),
  CONSTRAINT `fk_reg_student` FOREIGN KEY (`student_id`) 
    REFERENCES `students` (`student_id`) 
    ON DELETE CASCADE 
    ON UPDATE CASCADE,
  CONSTRAINT `fk_reg_event` FOREIGN KEY (`event_id`) 
    REFERENCES `events` (`event_id`) 
    ON DELETE CASCADE 
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Seed Dataset: Users (Password for all demo accounts: 'password')
-- Bcrypt Hash: $2y$10$tsYhsIEyDbM3AZV4LmdNye0FJR2BbBsP2AcVTMtrncLLnoctq4iNq
-- ---------------------------------------------------------------------
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`) VALUES
(1, 'admin', 'admin@studenthub.local', '$2y$10$tsYhsIEyDbM3AZV4LmdNye0FJR2BbBsP2AcVTMtrncLLnoctq4iNq', 'admin'),
(2, 'aarav', 'aarav@example.edu', '$2y$10$tsYhsIEyDbM3AZV4LmdNye0FJR2BbBsP2AcVTMtrncLLnoctq4iNq', 'student'),
(3, 'diya', 'diya@example.edu', '$2y$10$tsYhsIEyDbM3AZV4LmdNye0FJR2BbBsP2AcVTMtrncLLnoctq4iNq', 'student');

-- ---------------------------------------------------------------------
-- Seed Dataset: Students
-- ---------------------------------------------------------------------
INSERT INTO `students` (`student_id`, `user_id`, `full_name`, `email`, `department`, `semester`, `phone`) VALUES
(1, 2, 'Aarav Shah', 'aarav@example.edu', 'Computer Science', 3, '+91 98765 43210'),
(2, 3, 'Diya Patel', 'diya@example.edu', 'Information Technology', 2, '+91 98765 43211'),
(3, NULL, 'Kabir Mehta', 'kabir@example.edu', 'Computer Science', 4, '+91 98765 43212'),
(4, NULL, 'Ananya Sharma', 'ananya@example.edu', 'Electronics & Comm.', 3, '+91 98765 43213'),
(5, NULL, 'Rohan Verma', 'rohan@example.edu', 'Mechanical Engineering', 5, '+91 98765 43214'),
(6, NULL, 'Isha Deshmukh', 'isha@example.edu', 'Data Science', 2, '+91 98765 43215');

-- ---------------------------------------------------------------------
-- Seed Dataset: Events
-- ---------------------------------------------------------------------
INSERT INTO `events` (`event_id`, `event_name`, `category`, `event_date`, `venue`, `max_seats`, `description`) VALUES
(1, 'Modern Full-Stack PHP Workshop', 'Technical Workshop', '2026-11-15', 'Advanced Computing Lab 2', 45, 'Comprehensive hands-on session covering PHP 8, PDO prepared statements, AJAX asynchronous data exchange, and security best practices.'),
(2, 'Annual College Tech Fest 2026', 'Campus Festival', '2026-12-05', 'Grand University Auditorium', 300, 'Flagship annual inter-collegiate technology symposium featuring hackathons, paper presentations, and algorithmic coding challenges.'),
(3, 'Career Guidance & Internship Fair', 'Career & Placement', '2026-11-22', 'Central Seminar Hall A', 120, 'Industry expert panel discussion and interactive guidance on technical interviews, resume building, and summer internship opportunities.'),
(4, 'Artificial Intelligence in Healthcare', 'Guest Lecture', '2026-12-18', 'Academic Block Hall 4', 80, 'Keynote address by visiting scholars on deep learning applications in medical diagnostics and predictive radiology.');

-- ---------------------------------------------------------------------
-- Seed Dataset: Registrations (Relational junction records)
-- ---------------------------------------------------------------------
INSERT INTO `registrations` (`registration_id`, `student_id`, `event_id`, `status`) VALUES
(1, 1, 1, 'confirmed'),
(2, 2, 1, 'confirmed'),
(3, 1, 2, 'confirmed'),
(4, 3, 2, 'confirmed'),
(5, 2, 3, 'confirmed');
