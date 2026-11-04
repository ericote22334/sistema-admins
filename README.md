# Grupo Lux — Sistema Super Admin / Empleado / Cliente (PHP + MySQL)

Todos los archivos llevan el prefijo `lux_` para no chocar con los otros 3 sistemas del repo.

## Cómo correrlo
Requiere PHP 7.1+ con `pdo_mysql` y MySQL/MariaDB (vienen en XAMPP).
Iniciá Apache y MySQL desde el panel de XAMPP. Desde la carpeta raíz del proyecto también podés ejecutar:
```
php -S localhost:8000
```
Abrí http://localhost:8000. La base MySQL `grupolux` se crea y carga con datos demo la primera vez.

## Backend y base de datos
El backend usa MySQL/MariaDB mediante PDO y conecta por defecto a `127.0.0.1:3306` con el usuario `root` sin contraseña (configuración habitual de XAMPP). Al iniciar, crea la base `grupolux` si no existe e importa [`data/grupo_lux.sql`](data/grupo_lux.sql), que contiene las tablas y datos de demostración. Si tu servidor usa otras credenciales, definí `LUX_DB_HOST`, `LUX_DB_PORT`, `LUX_DB_NAME`, `LUX_DB_USER` y `LUX_DB_PASS` en el entorno de PHP.

Para importar el archivo manualmente desde phpMyAdmin, creá y seleccioná una base llamada `grupolux`, luego importá `data/grupo_lux.sql`. La cuenta de MySQL debe poder crear tablas e insertar registros; para la instalación automática también debe poder crear la base de datos.

La base relaciona negocios, usuarios, productos, ventas y turnos mediante claves foráneas e índices. El archivo `data/lux.sqlite`, si ya existe de una ejecución anterior, es una base SQLite antigua y ya no la utiliza la aplicación.

## Usuarios demo (clave `lux2026`)
- Super Admin: admin@grupolux.com
- Empleado (Farmacia): diego.ferro@grupolux.com
- Administrador (Papelería): marina.rios@grupolux.com
- Cliente: ivana.roldan@mail.com

## Archivos
| Archivo | Qué hace |
|---|---|
| lux_config.php | Conexión MySQL, instalación de tablas y datos demo, sesión, helpers, CSRF |
| lux_layout.php | Cabecera/sidebar por rol y componentes comunes |
| lux_login.php / lux_logout.php | Ingreso y salida (password_hash) |
| lux_panel_general.php | Super Admin: KPIs y gráfico de ventas reales |
| lux_negocios.php | Super Admin: estado de los 4 negocios |
| lux_usuarios.php | Super Admin: gestionar usuarios; Administrador: agregar empleados a su negocio |
| lux_ventas.php | Empleado/Admin: resumen y registrar venta (descuenta stock) |
| lux_inventario.php | Empleado/Admin: buscar, alta de productos y reposición |
| lux_mis_turnos.php | Cliente: compras, reservar y cancelar turnos |
| lux_explorar.php | Cliente: catálogo de negocios |
| lux_perfil.php | Todos: editar nombre y cambiar contraseña |
