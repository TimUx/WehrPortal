-- openwehr needs CREATEDB so that pest --parallel can create its worker databases
ALTER USER openwehr CREATEDB;
-- Separate database for the test suite (phpunit.xml: openwehr_test)
CREATE DATABASE openwehr_test OWNER openwehr;
