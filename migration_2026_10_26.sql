-- Migration สำหรับ Class & Group Manager
-- เลือกฐานข้อมูล gg ก่อนรันใน phpMyAdmin > SQL
-- ปลอดภัยต่อการรันซ้ำ: เพิ่ม classrooms.description เฉพาะเมื่อยังไม่มี

SET @has_description = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'classrooms'
    AND COLUMN_NAME = 'description'
);
SET @migration_sql = IF(
  @has_description = 0,
  'ALTER TABLE classrooms ADD COLUMN description TEXT NULL',
  'SELECT ''classrooms.description มีอยู่แล้ว'' AS migration_status'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- เก็บทักษะที่นักเรียนพิมพ์เอง จะกรอกหรือเว้นว่างก็ได้
CREATE TABLE IF NOT EXISTS user_custom_skills (
  user_id INT NOT NULL PRIMARY KEY,
  skills_text TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ป้องกันชื่อห้องเรียนซ้ำกันในระบบ หากไม่มีชื่อซ้ำอยู่ก่อนแล้ว
-- หากมีชื่อซ้ำอยู่ก่อน คำสั่งจะแจ้งเตือนและไม่ลบ/แก้ข้อมูลเดิม
SET @has_class_name_unique = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'classrooms'
    AND INDEX_NAME = 'uq_classrooms_class_name'
);
SET @duplicate_class_names = (
  SELECT COUNT(*) FROM (
    SELECT LOWER(TRIM(class_name)) AS normalized_name
    FROM classrooms GROUP BY LOWER(TRIM(class_name)) HAVING COUNT(*) > 1
  ) AS duplicated_names
);
SET @sql_class_name_unique = IF(
  @has_class_name_unique > 0,
  'SELECT ''ข้อบังคับชื่อห้องเรียนซ้ำมีอยู่แล้ว'' AS migration_status',
  IF(@duplicate_class_names = 0,
    'ALTER TABLE classrooms ADD UNIQUE KEY uq_classrooms_class_name (class_name)',
    'SELECT ''พบชื่อห้องเรียนซ้ำเดิม: ยังไม่ได้เพิ่ม UNIQUE กรุณาตรวจชื่อห้องซ้ำก่อน'' AS migration_status'
  )
);
PREPARE class_name_stmt FROM @sql_class_name_unique;
EXECUTE class_name_stmt;
DEALLOCATE PREPARE class_name_stmt;

-- ป้องกันนักเรียนคนเดิมลงทะเบียนห้องเดิมซ้ำ หากไม่มีข้อมูลซ้ำอยู่ก่อนแล้ว
SET @has_enrollment_unique = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollments'
    AND INDEX_NAME = 'uq_enrollments_classroom_student'
);
SET @duplicate_enrollments = (
  SELECT COUNT(*) FROM (
    SELECT classroom_id, student_id
    FROM enrollments GROUP BY classroom_id, student_id HAVING COUNT(*) > 1
  ) AS duplicated_enrollments
);
SET @sql_enrollment_unique = IF(
  @has_enrollment_unique > 0,
  'SELECT ''ข้อบังคับการลงทะเบียนซ้ำมีอยู่แล้ว'' AS migration_status',
  IF(@duplicate_enrollments = 0,
    'ALTER TABLE enrollments ADD UNIQUE KEY uq_enrollments_classroom_student (classroom_id, student_id)',
    'SELECT ''พบการลงทะเบียนซ้ำเดิม: ยังไม่ได้เพิ่ม UNIQUE กรุณาตรวจข้อมูลซ้ำก่อน'' AS migration_status'
  )
);
PREPARE enrollment_stmt FROM @sql_enrollment_unique;
EXECUTE enrollment_stmt;
DEALLOCATE PREPARE enrollment_stmt;
