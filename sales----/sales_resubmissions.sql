CREATE TABLE `sales_resubmissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sales_request_id` int(11) NOT NULL,
  `resubmitted_by` int(11) NOT NULL,
  `original_data` text NOT NULL,
  `resubmitted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sales_request_id` (`sales_request_id`),
  KEY `resubmitted_by` (`resubmitted_by`),
  CONSTRAINT `sr_fk_sales_request` FOREIGN KEY (`sales_request_id`) REFERENCES `sales_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sr_fk_user` FOREIGN KEY (`resubmitted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;