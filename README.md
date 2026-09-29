# Grupo Lux — Sistema Super Admin / Empleado / Cliente (PHP + SQLite)

Todos los archivos llevan el prefijo `lux_` para no chocar con los otros 3 sistemas del repo.

## Cómo correrlo
Requiere PHP 8+ con `pdo_sqlite` (viene en XAMPP).
```
cd grupo-lux-sistema
php -S localhost:8000
```
Abrir http://localhost:8000. La base (`data/lux.sqlite`) se crea sola con datos demo la primera vez.
Para reiniciarla, borrar la carpeta `data/`.

## Usuarios demo (clave `lux2026`)
- Super Admin: admin@grupolux.com
- Empleado (Farmacia): diego.ferro@grupolux.com
- Administrador (Papelería): marina.rios@grupolux.com
- Cliente: ivana.roldan@mail.com

## Archivos
| Archivo | Qué hace |
|---|---|
| lux_config.php | Conexión, creación de tablas + datos demo, sesión, helpers, CSRF |
| lux_layout.php | Cabecera/sidebar por rol y componentes comunes |
| lux_login.php / lux_logout.php | Ingreso y salida (password_hash) |
| lux_panel_general.php | Super Admin: KPIs y gráfico de ventas reales |
| lux_negocios.php | Super Admin: estado de los 4 negocios |
| lux_usuarios.php | Super Admin: buscar, crear, suspender/reactivar usuarios |
| lux_ventas.php | Empleado/Admin: resumen y registrar venta (descuenta stock) |
| lux_inventario.php | Empleado/Admin: buscar, alta de productos y reposición |
| lux_mis_turnos.php | Cliente: compras, reservar y cancelar turnos |
| lux_explorar.php | Cliente: catálogo de negocios |
| lux_perfil.php | Todos: editar nombre y cambiar contraseña |
