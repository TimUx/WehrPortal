-- wehrportal needs CREATEDB so that pest --parallel can create its worker databases
ALTER USER wehrportal CREATEDB;
-- Separate database for the test suite (phpunit.xml: wehrportal_test)
CREATE DATABASE wehrportal_test OWNER wehrportal;
