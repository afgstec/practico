<?php
	/*
	Copyright (C) 2013  John F. Arroyave Gutiérrez
						unix4you2@gmail.com

	This program is free software; you can redistribute it and/or
	modify it under the terms of the GNU General Public License
	as published by the Free Software Foundation; either version 2
	of the License, or (at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

	<pre>
	<b>Importante: Si usted esta visualizando este mensaje en su navegador,
	entonces PHP no esta instalado correctamente en su servidor web!</b>
	</pre>
	*/

	/*
		Title: WS_API
		Ubicacion *[core/ws_api.php]*.  Archivo con las funciones para llamado por medio de WebServices
		
		El consumo de los web services requiere el envio de los siguientes parametros minimos a la raiz de Practico en cada llamado:
		
		* WSOn=1: Siempre iniciado en 1 indica a Practico que debe activar el modo de WebServices
		* WSKey: La llave generada para consumir los WebServices, la llave de paso de instalacion es incluida por defecto
		* WSId: El identificador unico del metodo o funcion de webservices a llamar
		* OTROS: Parametros adicionales requeridos por la funcion pueden ser enviados por URL o metodo POST al llamar el WebService.

		Ejemplo:  www.sudominio.com/practico/?WSOn=1&WSKey=AFSX345DF&WSId=verificar_credenciales
		
		Recomendacion:  La generacion de avisos de tipo Notice, Warning, Error o similares de PHP puede ocasionar la emision
		de valores previos a la respuesta del WebService, se recomienda tener el modo de depuracion desactivado o verificar que los
		parametros y funciones utilizadas por cada webservice generen una salida limpia en caso de tener la depuracion activada.
	*/


/* ################################################################## */
/* ################################################################## */
/*
	Function: verificar_credenciales
	Realiza proceso de verificacion de credenciales para un inicio de sesion

	Variables de entrada:

		uid - Obligatorio: Login utilizado por el usuario
		clave - Obligatorio: Clave del usuario sin cifrar

	Salida:
		Archivo XML que contiene la propiedad de aceptacion en cero o uno (0,1) dependiendo de si las credenciales son o no validas.
		Complemento del archivo XML con datos generales del usuario como login, nombre, descripcion, nivel, correo y fecha de ultimo acceso

	Ver tambien:
		<Iniciar_login> | <cambiar_clave>
*/
if ($WSId=="verificar_credenciales") 
	{
		$uid=filtrar_cadena_sql(@$uid);
		$clave=filtrar_cadena_sql(@$clave);
		$salida_xml="";
		$ok_login_verifica='0';
		$error_parametros=0;		

		// Verifica parametros minimos para trabajar
		if ($uid=="" || $clave=="")
			$error_parametros=1;
		
		//Verifica MOTOR autenticacion interna
		if (!$error_parametros && ($Auth_TipoMotor=="practico" || $uid=="admin"))
			{
				$ClaveEnMD5=hash("md5", $clave);
				$resultado_usuario=ejecutar_sql("SELECT * FROM ".$TablasCore."usuario WHERE estado=1 AND login='$uid' AND clave='$ClaveEnMD5' ");
				$registro = $resultado_usuario->fetch();
				if ($registro["login"]!="")
					$ok_login_verifica='1';
			}

		//Verifica MOTOR autenticacion por LDAP
		if (!$error_parametros && ($Auth_TipoMotor=="ldap" && $uid!="admin"))
			{
				$auth_ldap_dc="";
				$auth_ldap_dc_trozos=explode(".",$Auth_LDAPDominio);
				for ($i = 0; $i < count($auth_ldap_dc_trozos) ; $i++)
					$auth_ldap_dc.=",dc=".$auth_ldap_dc_trozos[$i];
				$auth_ldap_cadena = 'uid='.$uid.',ou='.$Auth_LDAPOU.$auth_ldap_dc;
				// Conexion a LDAP
				$auth_ldap_conexion = ldap_connect( $Auth_LDAPServidor, $Auth_LDAPPuerto );
				//if no coneccion  or echo ("No se puede conectar a LDAP");
				//ldap_set_option($auth_ldap_conexion, LDAP_OPT_PROTOCOL_VERSION, 3);
				//Verifica si se debe preencriptar la clave
				if ($Auth_TipoEncripcion!="plano")
					$clave=hash($Auth_TipoEncripcion, $clave);
				// match de usuario y password
				if (  ldap_bind( $auth_ldap_conexion, $auth_ldap_cadena, $clave )  )
					$ok_login_verifica='1';
				// Si logra el acceso por LDAP consulta datos del usuario sobre Practico para llenar el XML pero
				// Si el usuario no existe se devolvera el valor de aceptacion solamente y el resto vacios
				$resultado_usuario=ejecutar_sql("SELECT * FROM ".$TablasCore."usuario WHERE login='$uid' ");
				$registro = $resultado_usuario->fetch();
				//echo $auth_ldap_cadena;
			}

		// Inicia el XML de salida basico solamente con el estado de aceptacion
		$salida_xml .= "<?xml version=\"1.0\" encoding=\"utf-8\" ?>
			<credenciales>
				<credencial>
					<aceptacion>$ok_login_verifica</aceptacion>";
		// Agrega al XML informacion complementaria cuando el estado de aceptacion es 1//// y ademas el motor interno es practico
		if ($ok_login_verifica=='1')
			$salida_xml .= "
				<login>".$registro["login"]."</login>
				<nombre>".$registro["nombre"]."</nombre>
				<descripcion>".$registro["descripcion"]."</descripcion>
				<nivel>".$registro["nivel"]."</nivel>
				<correo>".$registro["correo"]."</correo>
				<ultimo_acceso>".$registro["ultimo_acceso"]."</ultimo_acceso>";
		// Finaliza el archivo XML
		$salida_xml .= "
			</credencial>
		</credenciales>";
		// Devuelve los resultados
		echo $salida_xml;
	}

/* ################################################################## */
/* ################################################################## */
/*
	Function: autenticacion_google
	Realiza proceso de llamado a la API de autenticacion de Google para permitir el acceso del usuario

	Salida:
		Redireccion del usuario a la pagina de login de google, quien a su vez redireccionara al usuario segun el resultado

	Ver tambien:
		<Iniciar_login>
*/
if ($WSId=="autenticacion_google") 
	{
		require_once 'mod/google-api/Google_Client.php';
		require_once 'mod/google-api/contrib/Google_PlusService.php';

		// Set your cached access token. Remember to replace $_SESSION with a
		// real database or memcached.
		session_start();

		$client = new Google_Client();
		$client->setApplicationName('Autenticacion Practico');
		// Visit https://code.google.com/apis/console?api=plus to generate your
		// client id, client secret, and to register your redirect uri.
		$client->setClientId('932485011824.apps.googleusercontent.com'); //insert_your_oauth2_client_id
		$client->setClientSecret('Ne6iR6YLWVKiGUVEpXdCfG00'); //insert_your_oauth2_client_secret
		$client->setRedirectUri('http://www.lexicomtheplace.com/practico/auth/valida.php'); //insert_your_oauth2_redirect_uri
		$client->setDeveloperKey('AIzaSyASqk3wVgXwLp7qEjQtl7F771xSw9Bx0sA'); //insert_your_simple_api_key
		$plus = new Google_PlusService($client);

		if (isset($_GET['code'])) {
		  $client->authenticate();
		  $_SESSION['token'] = $client->getAccessToken();
		  $redirect = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
		  header('Location: ' . filter_var($redirect, FILTER_SANITIZE_URL));
		}

		if (isset($_SESSION['token'])) {
		  $client->setAccessToken($_SESSION['token']);
		}

		if ($client->getAccessToken()) {
		  $activities = $plus->activities->listActivities('me', 'public');
		  print 'Your Activities: <pre>' . print_r($activities, true) . '</pre>';

		  // We're not done yet. Remember to update the cached access token.
		  // Remember to replace $_SESSION with a real database or memcached.
		  $_SESSION['token'] = $client->getAccessToken();
		} else {
		  $authUrl = $client->createAuthUrl();
		  header("Location: $authUrl"); // Redirecciona a la autenticacion de Google
		}
	}