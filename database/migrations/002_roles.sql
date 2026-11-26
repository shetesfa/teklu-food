-- ተክሉ ፉድ እና ቢዝነስ ማኔጅመንት (Teklu Food & Business Management)
-- Migration 002: አራት ሚናዎች (super_admin, admin, admin_worker, worker)
-- የቀድሞው 'manager' role ወደ 'admin' ይቀየራል።
-- ይህ ፋይል በ live ዳታቤዝ ላይ በተናጠል ይተገበራል (እዚህ commit ብቻ ነው፤
-- በዚህ push ምንም live ዳታ አይቀየርም)።
-- አተገባበር (TiDB / MySQL): mysql -h <host> -P 4000 -u <user> -p teklu_business_db < database/migrations/002_roles.sql

USE teklu_business_db;

-- 1. users.role ENUM ማስፋት
ALTER TABLE users MODIFY COLUMN role ENUM('super_admin', 'admin', 'admin_worker', 'worker') NOT NULL;

-- 2. የቀድሞው 'manager' role ወደ 'admin' መቀየር (username 'manager' ሳይቀየር ይቀራል)
UPDATE users SET role = 'admin' WHERE role = 'manager';

-- 3. ማረጋገጫ — በ role ብዛት (ከተተገበረ በኋላ 'manager' 0 መሆን አለበት)
SELECT role, COUNT(*) AS user_count FROM users GROUP BY role ORDER BY role;
