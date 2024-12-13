# RMC API Running and Set up

Welcome to RMC API

Original Author : Sinead O

---

### Local Installation

First of all, clone the project. Once you have done that, you need to create a database locally via MAMP or something equivalent

Once you have done this, change the DATABASE information in the .env file
To find your database socket, I will use the MAMP example, however, connect to your own local mysql;

`mysql -u root -p`
`mysql> show variables like '%sock%'`

Copy and paste the result for 'socket' in your .env file under DB_SOCKET

Once you have done this, ensure you run the following commands in terminal:

`composer install`
`npm install`

Then we need to run the database migrations to ensure the relevant database tables are set up:

`php artisan migrate` or `php artisan migrate --env=local`

---

### Installing Dummy Data
Enter this to install Dummy Data for login

`php artisan db:seed`

Then to make sure the server is correct, run the following command:

`php artisan serve` - This should run the install from http://127.0.0.1:8000

---

### Testing API Access to login

`curl -X POST localhost:8000/api/login \`
`  -H "Accept: application/json" \`
 ` -H "Content-type: application/json" \`
 ` -d "{\"email\": \"admin@test.com\", \"password\": \"demormc\" }"`

 ---

### To Create additional tables through migrations

To add a migration to the install for a database table, use the following command in terminal:

`php artisan make:model Users -m ` (Change `Users` to the name of the table)

This can then be found in database > migrations for amending. Once you have created any amendments, run the following command

`php artisan migrate:refresh`

---

### (COMMON ERRORS)
- Field doesn't have a default value: MySQL is most likely in STRICT SQL mode. Use `mysql -u root -p` then `set @@sql_mode = ''` within your terminal 

