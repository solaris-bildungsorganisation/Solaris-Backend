-- MySQL 8: DB-level tests for Wave 1 business rules. Run AFTER migrate + db:seed on a disposable database:
--   mysql --force solaris < tools/constraint_tests.sql
INSERT INTO users (uuid, first_name, last_name, email, password) VALUES (UUID(),'A','One','Kotaiba@Example.com','x');
INSERT INTO users (uuid, first_name, last_name, phone, password) VALUES (UUID(),'B','Two','+963900000000','x');
INSERT INTO organizations (uuid, name, slug, type) VALUES (UUID(),'{"ar":"منظمة"}','org-1','ngo');

SELECT '--- T1 FR-AUTH-008 duplicate email (case-insensitive) => expect ERROR' AS t;
INSERT INTO users (uuid, first_name, last_name, email, password) VALUES (UUID(),'C','Dup','kotaiba@example.com','x');
SELECT '--- T2 contact required => expect ERROR' AS t;
INSERT INTO users (uuid, first_name, last_name, password) VALUES (UUID(),'D','NoContact','x');
SELECT '--- T3 FR-AUTH-005 self grant => expect ERROR' AS t;
INSERT INTO model_has_roles (role_id, model_type, model_id, scope_id, granted_by) SELECT r.id, 'App\\Models\\User', u.id, 1, u.id FROM users u, roles r WHERE u.email='Kotaiba@Example.com' AND r.name='platform_admin';
SELECT '--- T4 system grant learner in global scope => expect OK' AS t;
INSERT INTO model_has_roles (role_id, model_type, model_id, scope_id) SELECT r.id, 'App\\Models\\User', u.id, 1 FROM users u, roles r WHERE u.email='Kotaiba@Example.com' AND r.name='learner';
SELECT '--- T5 same role in same scope twice => expect ERROR' AS t;
INSERT INTO model_has_roles (role_id, model_type, model_id, scope_id) SELECT r.id, 'App\\Models\\User', u.id, 1 FROM users u, roles r WHERE u.email='Kotaiba@Example.com' AND r.name='learner';
SELECT '--- T6 malformed scope (organization without org) => expect ERROR' AS t;
INSERT INTO access_scopes (type) VALUES ('organization');
SELECT '--- T7 org scope + org admin granted by another user => expect OK' AS t;
INSERT INTO access_scopes (type, organization_id) SELECT 'organization', id FROM organizations WHERE slug='org-1';
INSERT INTO model_has_roles (role_id, model_type, model_id, scope_id, granted_by) SELECT r.id, 'App\\Models\\User', u.id, s.id, g.id FROM users u, roles r, access_scopes s, organizations o, users g WHERE u.phone='+963900000000' AND r.name='organization_admin' AND s.organization_id=o.id AND o.slug='org-1' AND g.email='Kotaiba@Example.com';
SELECT '--- T8 audit log insert ok, update/delete blocked => expect 2 ERRORs' AS t;
INSERT INTO activity_log (log_name, description, sensitivity, created_at, updated_at) VALUES ('rbac','role granted','restricted', NOW(), NOW());
UPDATE activity_log SET description='tampered';
DELETE FROM activity_log;
SELECT '--- T9 audit purge allowed only with session flag => expect 0 rows left' AS t;
SET @solaris_allow_audit_purge = 1; DELETE FROM activity_log; SET @solaris_allow_audit_purge = NULL;
SELECT COUNT(*) AS audit_rows_left FROM activity_log;
SELECT '--- T10 publish story without consent => expect ERROR' AS t;
INSERT INTO success_stories (uuid, title, body, status, consent_status, created_by) SELECT UUID(),'{"ar":"قصة"}','{"ar":"نص"}','published','pending', id FROM users WHERE email='Kotaiba@Example.com';
SELECT '--- T11 two default contexts => expect 1 OK then ERROR' AS t;
INSERT INTO user_contexts (user_id, context_id, is_default) SELECT u.id, c.id, 1 FROM users u, contexts c WHERE u.email='Kotaiba@Example.com' AND c.`key`='learning';
INSERT INTO user_contexts (user_id, context_id, is_default) SELECT u.id, c.id, 1 FROM users u, contexts c WHERE u.email='Kotaiba@Example.com' AND c.`key`='teaching';
SELECT '--- T12 duplicate global region policy => expect 1 OK then ERROR' AS t;
INSERT INTO region_policies (policy_key, value) VALUES ('courses.free_access','true');
INSERT INTO region_policies (policy_key, value) VALUES ('courses.free_access','false');
SELECT '--- T13 invalid user status => expect ERROR' AS t;
UPDATE users SET status='deleted' WHERE email='Kotaiba@Example.com';
