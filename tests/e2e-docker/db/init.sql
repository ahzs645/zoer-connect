-- Two isolated WordPress databases on one disposable server.
CREATE DATABASE source CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE dest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'source'@'%' IDENTIFIED BY 'source-e2e';
CREATE USER 'dest'@'%' IDENTIFIED BY 'dest-e2e';
GRANT ALL PRIVILEGES ON source.* TO 'source'@'%';
GRANT ALL PRIVILEGES ON dest.* TO 'dest'@'%';
FLUSH PRIVILEGES;
