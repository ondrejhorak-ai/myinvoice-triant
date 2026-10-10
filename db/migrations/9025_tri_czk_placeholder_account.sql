-- Cvičný účet CZK v nastavení. Doklad ho čte odtud, číslo není v šabloně.
-- Doplní se jen prázdný účet, ať se pozdější skutečný účet nepřepíše.

UPDATE currencies
   SET account_number = '0000000019',
       bank_code = '0000',
       bank_name = 'Testovací účet (cvičná data)'
 WHERE code = 'CZK'
   AND (account_number IS NULL OR account_number = '')
   AND (iban IS NULL OR iban = '');
