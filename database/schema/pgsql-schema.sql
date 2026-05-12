--
-- PostgreSQL database dump
--

\restrict xngnA6RZHWANrKYKzV1S0IDIQW5EONyJdqfVhn633qbXdiv8nxvutmIczQI6aXd

-- Dumped from database version 16.13
-- Dumped by pg_dump version 16.11

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    id bigint NOT NULL,
    key character varying(255) NOT NULL,
    value text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: cache_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.cache_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: cache_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.cache_id_seq OWNED BY public.cache.id;


--
-- Name: cargos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cargos (
    id bigint NOT NULL,
    codigo character varying(30) NOT NULL,
    nombre character varying(120) NOT NULL,
    activo boolean DEFAULT true NOT NULL,
    aud_usuario character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: cargos_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.cargos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: cargos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.cargos_id_seq OWNED BY public.cargos.id;


--
-- Name: centros_costo; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.centros_costo (
    id bigint NOT NULL,
    nombre character varying(255) NOT NULL,
    codigo character varying(255) NOT NULL,
    aud_usuario character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    activo boolean DEFAULT true NOT NULL,
    padre_id bigint,
    es_sub boolean DEFAULT true NOT NULL,
    sucursal_id bigint
);


--
-- Name: centros_costo_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.centros_costo_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: centros_costo_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.centros_costo_id_seq OWNED BY public.centros_costo.id;


--
-- Name: departamentos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.departamentos (
    id bigint NOT NULL,
    codigo character varying(30) NOT NULL,
    nombre character varying(150) NOT NULL,
    descripcion text,
    parent_id bigint,
    sucursal_id bigint,
    jefe_empleado_id bigint,
    activo boolean DEFAULT true NOT NULL,
    aud_usuario character varying(150),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: departamentos_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.departamentos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: departamentos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.departamentos_id_seq OWNED BY public.departamentos.id;


--
-- Name: email_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.email_logs (
    id bigint NOT NULL,
    sistema character varying(60) NOT NULL,
    tipo character varying(80) NOT NULL,
    destinatario character varying(255) NOT NULL,
    asunto character varying(500),
    estado character varying(20) NOT NULL,
    error_mensaje text,
    respuesta_api text,
    referencia_id bigint,
    referencia_numero character varying(20),
    referencia_tipo character varying(60),
    enviado_por character varying(100),
    metadata jsonb,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: email_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.email_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: email_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.email_logs_id_seq OWNED BY public.email_logs.id;


--
-- Name: empleado_jefaturas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.empleado_jefaturas (
    id bigint NOT NULL,
    empleado_id bigint NOT NULL,
    tipo_jefatura_id bigint NOT NULL,
    sucursal_id bigint,
    activo boolean DEFAULT true NOT NULL,
    aud_usuario character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: empleado_jefaturas_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.empleado_jefaturas_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: empleado_jefaturas_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.empleado_jefaturas_id_seq OWNED BY public.empleado_jefaturas.id;


--
-- Name: empleados; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.empleados (
    id bigint NOT NULL,
    codigo character varying(20) NOT NULL,
    nombres character varying(120) NOT NULL,
    apellidos character varying(120) NOT NULL,
    email character varying(120),
    cargo_id bigint,
    sucursal_id bigint,
    activo boolean DEFAULT true NOT NULL,
    aud_usuario character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id bigint,
    fecha_ingreso date,
    departamento_id bigint
);


--
-- Name: empleados_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.empleados_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: empleados_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.empleados_id_seq OWNED BY public.empleados.id;


--
-- Name: geo_departamentos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.geo_departamentos (
    id smallint NOT NULL,
    codigo character varying(2) NOT NULL,
    nombre character varying(80) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: geo_departamentos_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.geo_departamentos_id_seq
    AS smallint
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: geo_departamentos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.geo_departamentos_id_seq OWNED BY public.geo_departamentos.id;


--
-- Name: geo_distritos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.geo_distritos (
    id smallint NOT NULL,
    departamento_id smallint NOT NULL,
    codigo character varying(6) NOT NULL,
    nombre character varying(100) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: geo_distritos_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.geo_distritos_id_seq
    AS smallint
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: geo_distritos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.geo_distritos_id_seq OWNED BY public.geo_distritos.id;


--
-- Name: geo_municipios; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.geo_municipios (
    id smallint NOT NULL,
    departamento_id smallint NOT NULL,
    distrito_id smallint NOT NULL,
    codigo character varying(6),
    nombre character varying(100) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: geo_municipios_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.geo_municipios_id_seq
    AS smallint
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: geo_municipios_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.geo_municipios_id_seq OWNED BY public.geo_municipios.id;


--
-- Name: horarios_empleado; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.horarios_empleado (
    id bigint NOT NULL,
    empleado_id bigint NOT NULL,
    fecha date NOT NULL,
    hora_inicio time(0) without time zone,
    hora_fin time(0) without time zone,
    tipo character varying(20) DEFAULT 'normal'::character varying NOT NULL,
    notas character varying(200),
    aud_usuario character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: horarios_empleado_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.horarios_empleado_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: horarios_empleado_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.horarios_empleado_id_seq OWNED BY public.horarios_empleado.id;


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: permission_role; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.permission_role (
    id bigint NOT NULL,
    permission_id bigint NOT NULL,
    role_id bigint NOT NULL,
    aud_usuario character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: permission_role_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.permission_role_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: permission_role_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.permission_role_id_seq OWNED BY public.permission_role.id;


--
-- Name: permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.permissions (
    id bigint NOT NULL,
    nombre character varying(255) NOT NULL,
    codigo character varying(255) NOT NULL,
    system_id bigint NOT NULL,
    aud_usuario character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: permissions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.permissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.permissions_id_seq OWNED BY public.permissions.id;


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id bigint NOT NULL,
    name text NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: role_user; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.role_user (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    role_id bigint NOT NULL,
    aud_usuario character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: role_user_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.role_user_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: role_user_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.role_user_id_seq OWNED BY public.role_user.id;


--
-- Name: roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.roles (
    id bigint NOT NULL,
    nombre character varying(255) NOT NULL,
    codigo character varying(255) NOT NULL,
    system_id bigint NOT NULL,
    aud_usuario character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.roles_id_seq OWNED BY public.roles.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: sucursales; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sucursales (
    id bigint NOT NULL,
    nombre character varying(255) NOT NULL,
    codigo character varying(255) NOT NULL,
    aud_usuario character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    tipo_sucursal_id bigint,
    activa boolean DEFAULT true NOT NULL
);


--
-- Name: sucursales_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sucursales_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sucursales_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sucursales_id_seq OWNED BY public.sucursales.id;


--
-- Name: systems; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.systems (
    id bigint NOT NULL,
    nombre character varying(255) NOT NULL,
    codigo character varying(255) NOT NULL,
    aud_usuario character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    url character varying(255),
    color character varying(20) DEFAULT '#6366f1'::character varying NOT NULL,
    icon character varying(255),
    descripcion character varying(255)
);


--
-- Name: systems_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.systems_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: systems_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.systems_id_seq OWNED BY public.systems.id;


--
-- Name: tipos_jefatura; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tipos_jefatura (
    id bigint NOT NULL,
    codigo character varying(30) NOT NULL,
    nombre character varying(80) NOT NULL,
    descripcion character varying(200),
    activo boolean DEFAULT true NOT NULL,
    aud_usuario character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: tipos_jefatura_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tipos_jefatura_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tipos_jefatura_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tipos_jefatura_id_seq OWNED BY public.tipos_jefatura.id;


--
-- Name: tipos_sucursal; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tipos_sucursal (
    id bigint NOT NULL,
    codigo character varying(30) NOT NULL,
    nombre character varying(100) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: tipos_sucursal_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tipos_sucursal_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tipos_sucursal_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tipos_sucursal_id_seq OWNED BY public.tipos_sucursal.id;


--
-- Name: user_sucursales; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_sucursales (
    user_id bigint NOT NULL,
    sucursal_id bigint NOT NULL
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    password character varying(255) NOT NULL,
    activo boolean DEFAULT true NOT NULL,
    aud_usuario character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    sucursal_id bigint,
    force_password_change boolean DEFAULT false NOT NULL,
    reset_code character varying(6),
    reset_code_expires_at timestamp with time zone
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: cache id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache ALTER COLUMN id SET DEFAULT nextval('public.cache_id_seq'::regclass);


--
-- Name: cargos id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cargos ALTER COLUMN id SET DEFAULT nextval('public.cargos_id_seq'::regclass);


--
-- Name: centros_costo id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.centros_costo ALTER COLUMN id SET DEFAULT nextval('public.centros_costo_id_seq'::regclass);


--
-- Name: departamentos id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.departamentos ALTER COLUMN id SET DEFAULT nextval('public.departamentos_id_seq'::regclass);


--
-- Name: email_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.email_logs ALTER COLUMN id SET DEFAULT nextval('public.email_logs_id_seq'::regclass);


--
-- Name: empleado_jefaturas id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.empleado_jefaturas ALTER COLUMN id SET DEFAULT nextval('public.empleado_jefaturas_id_seq'::regclass);


--
-- Name: empleados id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.empleados ALTER COLUMN id SET DEFAULT nextval('public.empleados_id_seq'::regclass);


--
-- Name: geo_departamentos id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.geo_departamentos ALTER COLUMN id SET DEFAULT nextval('public.geo_departamentos_id_seq'::regclass);


--
-- Name: geo_distritos id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.geo_distritos ALTER COLUMN id SET DEFAULT nextval('public.geo_distritos_id_seq'::regclass);


--
-- Name: geo_municipios id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.geo_municipios ALTER COLUMN id SET DEFAULT nextval('public.geo_municipios_id_seq'::regclass);


--
-- Name: horarios_empleado id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.horarios_empleado ALTER COLUMN id SET DEFAULT nextval('public.horarios_empleado_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: permission_role id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permission_role ALTER COLUMN id SET DEFAULT nextval('public.permission_role_id_seq'::regclass);


--
-- Name: permissions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions ALTER COLUMN id SET DEFAULT nextval('public.permissions_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: role_user id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_user ALTER COLUMN id SET DEFAULT nextval('public.role_user_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles ALTER COLUMN id SET DEFAULT nextval('public.roles_id_seq'::regclass);


--
-- Name: sucursales id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sucursales ALTER COLUMN id SET DEFAULT nextval('public.sucursales_id_seq'::regclass);


--
-- Name: systems id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.systems ALTER COLUMN id SET DEFAULT nextval('public.systems_id_seq'::regclass);


--
-- Name: tipos_jefatura id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tipos_jefatura ALTER COLUMN id SET DEFAULT nextval('public.tipos_jefatura_id_seq'::regclass);


--
-- Name: tipos_sucursal id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tipos_sucursal ALTER COLUMN id SET DEFAULT nextval('public.tipos_sucursal_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: cache cache_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_key_unique UNIQUE (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (id);


--
-- Name: cargos cargos_codigo_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cargos
    ADD CONSTRAINT cargos_codigo_unique UNIQUE (codigo);


--
-- Name: cargos cargos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cargos
    ADD CONSTRAINT cargos_pkey PRIMARY KEY (id);


--
-- Name: centros_costo centros_costo_codigo_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.centros_costo
    ADD CONSTRAINT centros_costo_codigo_unique UNIQUE (codigo);


--
-- Name: centros_costo centros_costo_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.centros_costo
    ADD CONSTRAINT centros_costo_pkey PRIMARY KEY (id);


--
-- Name: departamentos departamentos_codigo_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.departamentos
    ADD CONSTRAINT departamentos_codigo_unique UNIQUE (codigo);


--
-- Name: departamentos departamentos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.departamentos
    ADD CONSTRAINT departamentos_pkey PRIMARY KEY (id);


--
-- Name: empleado_jefaturas ejef_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.empleado_jefaturas
    ADD CONSTRAINT ejef_unique UNIQUE (empleado_id, tipo_jefatura_id, sucursal_id);


--
-- Name: email_logs email_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.email_logs
    ADD CONSTRAINT email_logs_pkey PRIMARY KEY (id);


--
-- Name: empleado_jefaturas empleado_jefaturas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.empleado_jefaturas
    ADD CONSTRAINT empleado_jefaturas_pkey PRIMARY KEY (id);


--
-- Name: empleados empleados_codigo_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.empleados
    ADD CONSTRAINT empleados_codigo_unique UNIQUE (codigo);


--
-- Name: empleados empleados_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.empleados
    ADD CONSTRAINT empleados_pkey PRIMARY KEY (id);


--
-- Name: empleados empleados_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.empleados
    ADD CONSTRAINT empleados_user_id_unique UNIQUE (user_id);


--
-- Name: geo_departamentos geo_departamentos_codigo_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.geo_departamentos
    ADD CONSTRAINT geo_departamentos_codigo_unique UNIQUE (codigo);


--
-- Name: geo_departamentos geo_departamentos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.geo_departamentos
    ADD CONSTRAINT geo_departamentos_pkey PRIMARY KEY (id);


--
-- Name: geo_distritos geo_distritos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.geo_distritos
    ADD CONSTRAINT geo_distritos_pkey PRIMARY KEY (id);


--
-- Name: geo_municipios geo_municipios_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.geo_municipios
    ADD CONSTRAINT geo_municipios_pkey PRIMARY KEY (id);


--
-- Name: horarios_empleado horarios_emp_fecha_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.horarios_empleado
    ADD CONSTRAINT horarios_emp_fecha_unique UNIQUE (empleado_id, fecha);


--
-- Name: horarios_empleado horarios_empleado_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.horarios_empleado
    ADD CONSTRAINT horarios_empleado_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: permission_role permission_role_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permission_role
    ADD CONSTRAINT permission_role_pkey PRIMARY KEY (id);


--
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: role_user role_user_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_user
    ADD CONSTRAINT role_user_pkey PRIMARY KEY (id);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: sucursales sucursales_codigo_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sucursales
    ADD CONSTRAINT sucursales_codigo_unique UNIQUE (codigo);


--
-- Name: sucursales sucursales_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sucursales
    ADD CONSTRAINT sucursales_pkey PRIMARY KEY (id);


--
-- Name: systems systems_codigo_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.systems
    ADD CONSTRAINT systems_codigo_unique UNIQUE (codigo);


--
-- Name: systems systems_nombre_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.systems
    ADD CONSTRAINT systems_nombre_unique UNIQUE (nombre);


--
-- Name: systems systems_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.systems
    ADD CONSTRAINT systems_pkey PRIMARY KEY (id);


--
-- Name: tipos_jefatura tipos_jefatura_codigo_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tipos_jefatura
    ADD CONSTRAINT tipos_jefatura_codigo_unique UNIQUE (codigo);


--
-- Name: tipos_jefatura tipos_jefatura_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tipos_jefatura
    ADD CONSTRAINT tipos_jefatura_pkey PRIMARY KEY (id);


--
-- Name: tipos_sucursal tipos_sucursal_codigo_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tipos_sucursal
    ADD CONSTRAINT tipos_sucursal_codigo_unique UNIQUE (codigo);


--
-- Name: tipos_sucursal tipos_sucursal_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tipos_sucursal
    ADD CONSTRAINT tipos_sucursal_pkey PRIMARY KEY (id);


--
-- Name: user_sucursales user_sucursales_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_sucursales
    ADD CONSTRAINT user_sucursales_pkey PRIMARY KEY (user_id, sucursal_id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: email_logs_destinatario_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX email_logs_destinatario_created_at_index ON public.email_logs USING btree (destinatario, created_at);


--
-- Name: email_logs_estado_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX email_logs_estado_created_at_index ON public.email_logs USING btree (estado, created_at);


--
-- Name: email_logs_referencia_id_referencia_tipo_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX email_logs_referencia_id_referencia_tipo_index ON public.email_logs USING btree (referencia_id, referencia_tipo);


--
-- Name: email_logs_sistema_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX email_logs_sistema_created_at_index ON public.email_logs USING btree (sistema, created_at);


--
-- Name: geo_distritos_departamento_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX geo_distritos_departamento_id_index ON public.geo_distritos USING btree (departamento_id);


--
-- Name: geo_municipios_departamento_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX geo_municipios_departamento_id_index ON public.geo_municipios USING btree (departamento_id);


--
-- Name: geo_municipios_distrito_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX geo_municipios_distrito_id_index ON public.geo_municipios USING btree (distrito_id);


--
-- Name: personal_access_tokens_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_expires_at_index ON public.personal_access_tokens USING btree (expires_at);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: centros_costo cecos_sucursal_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.centros_costo
    ADD CONSTRAINT cecos_sucursal_fk FOREIGN KEY (sucursal_id) REFERENCES public.sucursales(id) ON DELETE SET NULL;


--
-- Name: departamentos dept_parent_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.departamentos
    ADD CONSTRAINT dept_parent_fk FOREIGN KEY (parent_id) REFERENCES public.departamentos(id) ON DELETE SET NULL;


--
-- Name: departamentos dept_sucursal_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.departamentos
    ADD CONSTRAINT dept_sucursal_fk FOREIGN KEY (sucursal_id) REFERENCES public.sucursales(id) ON DELETE SET NULL;


--
-- Name: empleado_jefaturas ejef_empleado_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.empleado_jefaturas
    ADD CONSTRAINT ejef_empleado_fk FOREIGN KEY (empleado_id) REFERENCES public.empleados(id) ON DELETE CASCADE;


--
-- Name: empleado_jefaturas ejef_sucursal_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.empleado_jefaturas
    ADD CONSTRAINT ejef_sucursal_fk FOREIGN KEY (sucursal_id) REFERENCES public.sucursales(id) ON DELETE SET NULL;


--
-- Name: empleado_jefaturas ejef_tipo_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.empleado_jefaturas
    ADD CONSTRAINT ejef_tipo_fk FOREIGN KEY (tipo_jefatura_id) REFERENCES public.tipos_jefatura(id) ON DELETE RESTRICT;


--
-- Name: empleados emp_cargo_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.empleados
    ADD CONSTRAINT emp_cargo_fk FOREIGN KEY (cargo_id) REFERENCES public.cargos(id) ON DELETE SET NULL;


--
-- Name: empleados emp_departamento_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.empleados
    ADD CONSTRAINT emp_departamento_fk FOREIGN KEY (departamento_id) REFERENCES public.departamentos(id) ON DELETE SET NULL;


--
-- Name: empleados emp_sucursal_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.empleados
    ADD CONSTRAINT emp_sucursal_fk FOREIGN KEY (sucursal_id) REFERENCES public.sucursales(id) ON DELETE SET NULL;


--
-- Name: empleados empleados_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.empleados
    ADD CONSTRAINT empleados_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: geo_distritos geo_distritos_departamento_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.geo_distritos
    ADD CONSTRAINT geo_distritos_departamento_id_foreign FOREIGN KEY (departamento_id) REFERENCES public.geo_departamentos(id) ON DELETE CASCADE;


--
-- Name: geo_municipios geo_municipios_departamento_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.geo_municipios
    ADD CONSTRAINT geo_municipios_departamento_id_foreign FOREIGN KEY (departamento_id) REFERENCES public.geo_departamentos(id) ON DELETE CASCADE;


--
-- Name: geo_municipios geo_municipios_distrito_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.geo_municipios
    ADD CONSTRAINT geo_municipios_distrito_id_foreign FOREIGN KEY (distrito_id) REFERENCES public.geo_distritos(id) ON DELETE CASCADE;


--
-- Name: horarios_empleado horarios_emp_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.horarios_empleado
    ADD CONSTRAINT horarios_emp_fk FOREIGN KEY (empleado_id) REFERENCES public.empleados(id) ON DELETE CASCADE;


--
-- Name: permission_role permission_role_permission_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permission_role
    ADD CONSTRAINT permission_role_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES public.permissions(id);


--
-- Name: permission_role permission_role_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permission_role
    ADD CONSTRAINT permission_role_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id);


--
-- Name: permissions permissions_system_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_system_id_foreign FOREIGN KEY (system_id) REFERENCES public.systems(id);


--
-- Name: role_user role_user_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_user
    ADD CONSTRAINT role_user_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id);


--
-- Name: role_user role_user_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_user
    ADD CONSTRAINT role_user_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: roles roles_system_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_system_id_foreign FOREIGN KEY (system_id) REFERENCES public.systems(id);


--
-- Name: sucursales sucursales_tipo_sucursal_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sucursales
    ADD CONSTRAINT sucursales_tipo_sucursal_id_foreign FOREIGN KEY (tipo_sucursal_id) REFERENCES public.tipos_sucursal(id);


--
-- Name: user_sucursales user_sucursales_sucursal_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_sucursales
    ADD CONSTRAINT user_sucursales_sucursal_id_foreign FOREIGN KEY (sucursal_id) REFERENCES public.sucursales(id) ON DELETE CASCADE;


--
-- Name: user_sucursales user_sucursales_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_sucursales
    ADD CONSTRAINT user_sucursales_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: users users_sucursal_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_sucursal_id_foreign FOREIGN KEY (sucursal_id) REFERENCES public.sucursales(id) ON DELETE SET NULL;


--
-- PostgreSQL database dump complete
--

\unrestrict xngnA6RZHWANrKYKzV1S0IDIQW5EONyJdqfVhn633qbXdiv8nxvutmIczQI6aXd

--
-- PostgreSQL database dump
--

\restrict hfvmvY5XAG3JMix02y63GkXJEcJXG2TZ4zOMh8ohBaN6lIDv8Cwcp2TRbh9wNIt

-- Dumped from database version 16.13
-- Dumped by pg_dump version 16.11

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	2026_03_16_000001_create_tipos_permiso_table	1
2	2026_03_16_000002_create_tipos_incapacidad_table	1
3	2026_03_16_000003_create_tipos_falta_table	1
4	2026_03_16_000004_create_motivos_desvinculacion_table	1
5	2026_03_16_000005_create_tipos_aumento_salarial_table	1
6	2026_03_16_000006_create_permisos_table	1
7	2026_03_16_000007_create_saldos_vacaciones_table	1
8	2026_03_16_000008_create_vacaciones_table	1
9	2026_03_16_000009_create_incapacidades_table	1
10	2026_03_16_000010_create_amonestaciones_table	1
11	2026_03_16_000011_create_dias_suspension_table	1
12	2026_03_16_000012_create_desvinculaciones_table	1
13	2026_03_16_000013_create_traslados_table	1
14	2026_03_16_000014_create_cambios_salariales_table	1
15	0001_01_01_000000_create_users_table	2
16	0001_01_01_000001_create_cache_table	2
17	0001_01_01_000002_create_jobs_table	2
18	2026_02_20_024947_create_sucursales_table	2
19	2026_02_20_024949_create_centros_costo_table	2
20	2026_02_20_024951_create_categorias_table	2
21	2026_02_20_024953_create_productos_table	2
22	2026_02_20_024956_create_pedidos_table	2
23	2026_02_20_024958_create_pedido_detalle_table	2
24	2026_02_24_204140_create_contribuyentes_table	2
25	2026_02_24_204148_create_formas_pago_table	2
26	2026_02_24_204150_create_proveedores_table	2
27	2026_02_24_204740_create_presupuestos_unidad_table	2
28	2026_02_26_000001_create_estados_solicitud_pago_table	2
29	2026_02_26_204324_create_solicitudes_pago_table	2
30	2026_02_26_204327_create_solicitud_pago_detalles_table	2
31	2026_02_26_204330_create_solicitud_pago_adjuntos_table	2
32	2026_02_27_000001_create_core_auth_tables	2
33	2026_03_02_000001_seed_centros_costo_data	2
34	2026_03_02_000002_add_padre_id_to_centros_costo	2
35	2026_03_02_000003_seed_real_centros_costo	2
36	2026_03_02_005432_create_personal_access_tokens_table	2
37	2026_03_02_100000_create_solicitud_pago_aprobaciones_table	2
38	2026_03_02_210000_create_tipos_persona_table	2
39	2026_03_02_210001_add_tipo_persona_id_to_proveedores	2
40	2026_03_02_300000_add_observado_to_aprobaciones_estado	2
41	2026_03_02_500000_create_user_centros_costo_table	2
42	2026_03_03_100000_create_ventas_semanales_table	2
43	2026_03_03_200000_create_compras_catalogo_tables	2
44	2026_03_04_000000_create_sessions_table	2
45	2026_03_04_100000_create_compras_recetas_tables	2
46	2026_03_05_100000_create_pedidos_compras_table	2
47	2026_03_05_100001_create_pedido_detalle_compras_table	2
48	2026_03_05_200000_add_sucursal_id_to_users	2
49	2026_03_05_300000_set_sucursal_id_on_existing_users	2
50	2026_03_05_400000_add_fk_sucursal_id_on_users	2
51	2026_03_05_500000_add_codigo_origen_to_compras_tables	2
52	2026_03_05_600000_create_receta_sucursal_table	2
53	2026_03_05_700000_add_unidad_to_pedido_detalle	2
54	2026_03_05_800000_add_tipo_contribuyente_to_proveedores	2
55	2026_03_05_900000_create_etiquetas_and_add_to_detalles	2
56	2026_03_09_100000_add_tipo_reseed_sucursales	2
57	2026_03_09_200000_add_sucursal_id_to_centros_costo	2
58	2026_03_09_300000_migrate_pivot_to_sucursal_id	2
59	2026_03_09_400000_create_tipos_jefatura_table	2
60	2026_03_09_500000_create_cargos_table	2
61	2026_03_09_600000_create_empleados_table	2
62	2026_03_09_700000_create_empleado_jefaturas_table	2
63	2026_03_10_000001_create_tipos_sucursal_table	2
64	2026_03_10_100000_add_mansion_to_core	2
65	2026_03_11_000001_add_ui_columns_to_systems	2
66	2026_03_11_100000_drop_role_id_from_users	2
67	2026_03_11_200000_add_user_id_to_empleados	2
68	2026_03_12_000001_add_pagado_to_estados_solicitud_pago	2
69	2026_03_16_000100_insert_rrhh_system	2
70	2026_03_16_000101_insert_rrhh_jefatura_role	2
71	2026_03_16_000102_add_fecha_ingreso_to_empleados	2
72	2026_03_16_200000_create_reglas_aprobacion_table	2
73	2026_03_17_000001_create_departamentos_table	3
74	2026_03_17_000002_add_departamento_id_to_empleados	3
75	2026_03_20_000001_create_receta_modificadores_table	4
76	2026_03_25_000001_add_origen_to_productos	5
77	2026_03_25_000002_add_fields_to_recetas	5
78	2026_03_25_000003_add_sub_receta_id_to_receta_ingredientes	5
79	2026_03_26_000001_create_unidades_medida_table	6
80	2026_03_27_000001_create_receta_categorias_table	7
81	2026_03_27_100001_create_expediente_tables	8
82	2026_03_28_000001_add_rendimiento_to_recetas_table	9
83	2026_03_29_171643_create_user_sucursales_table	10
84	2026_03_30_100001_add_activa_to_sucursales_cerrar_4	11
85	2026_03_30_100002_limpiar_receta_sucursal_cerradas	11
86	2026_03_30_100003_depurar_categorias_receta	11
87	2026_03_30_300001_add_fotos_to_expediente_documentos	12
88	2026_03_30_300002_create_expediente_idiomas_table	12
89	2026_03_30_300003_create_expediente_experiencia_laboral_table	12
90	2026_03_30_000001_create_geo_el_salvador_tables	13
91	2026_03_30_000002_add_geo_to_expediente_tables	14
92	2026_03_30_300004_create_expediente_cuentas_banco_table	15
93	2026_03_31_300005_add_coordenadas_to_expediente_direcciones	16
94	2026_04_01_300006_add_atestado_to_expediente_estudios	17
95	2026_04_01_400002_add_especializacion_to_expediente_estudios	18
96	2026_04_06_000001_add_conversion_to_productos	19
97	2026_04_08_000001_add_modificado_localmente_to_recetas_productos	20
98	2026_04_08_100001_add_tipo_institucion_to_incapacidades	21
99	2026_04_08_100002_create_ausencias_injustificadas_table	22
100	2026_04_08_100003_seed_tipos_permiso_nuevos	23
101	2026_04_09_000001_create_estados_receta_table	24
102	2026_04_09_100000_add_password_reset_to_users_table	25
103	2026_04_14_000001_create_horarios_empleado_table	26
104	2026_04_20_000001_create_inventarios_table	27
105	2026_04_20_000002_create_movimientos_inventario_table	28
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 105, true);


--
-- PostgreSQL database dump complete
--

\unrestrict hfvmvY5XAG3JMix02y63GkXJEcJXG2TZ4zOMh8ohBaN6lIDv8Cwcp2TRbh9wNIt

