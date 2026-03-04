--
-- PostgreSQL database dump
--

\restrict TY7lRbWiDizOOXQvhgkbdXCeTp5FKv1HuQsPJD9JOPazoFfqcgtWgumeJ1oLsY2

-- Dumped from database version 16.11
-- Dumped by pg_dump version 16.11

-- Started on 2026-03-04 10:37:03

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
-- TOC entry 2 (class 3079 OID 16399)
-- Name: pgcrypto; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;


--
-- TOC entry 5048 (class 0 OID 0)
-- Dependencies: 2
-- Name: EXTENSION pgcrypto; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION pgcrypto IS 'cryptographic functions';


--
-- TOC entry 930 (class 1247 OID 16814)
-- Name: epp_delivery_reason; Type: TYPE; Schema: public; Owner: postgres
--

CREATE TYPE public.epp_delivery_reason AS ENUM (
    'DOTACION PLANTA',
    'DOTACION SERVICIO',
    'DOTACION PROYECTOS',
    'CAMBIO PLANTA',
    'CAMBIO SERVICIOS',
    'CAMBIO PROYECTOS',
    'PERDIDA PLANTA',
    'PERDIDA SERVICIOS',
    'PERDIDA PROYECTOS'
);


ALTER TYPE public.epp_delivery_reason OWNER TO postgres;

--
-- TOC entry 271 (class 1255 OID 16436)
-- Name: epp_items_set_updated_at(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.epp_items_set_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$;


ALTER FUNCTION public.epp_items_set_updated_at() OWNER TO postgres;

--
-- TOC entry 272 (class 1255 OID 16437)
-- Name: reload_epp_stock(integer, integer, integer, text); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.reload_epp_stock(p_epp_item_id integer, p_qty integer, p_by integer, p_reason text) RETURNS void
    LANGUAGE plpgsql
    AS $$
DECLARE
  prev_stock INTEGER;
  new_stock INTEGER;
BEGIN
  IF p_qty <= 0 THEN
    RAISE EXCEPTION 'Cantidad de recarga debe ser mayor que 0';
  END IF;

  SELECT current_stock INTO prev_stock FROM epp_items WHERE id = p_epp_item_id FOR UPDATE;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'EPP item % no encontrado', p_epp_item_id;
  END IF;

  new_stock := prev_stock + p_qty;
  UPDATE epp_items SET current_stock = new_stock WHERE id = p_epp_item_id;

  INSERT INTO epp_stock_movements (epp_item_id, movement_type, quantity, previous_stock, new_stock, reason, created_by)
  VALUES (p_epp_item_id, 'in', p_qty, prev_stock, new_stock, p_reason, p_by);
END;
$$;


ALTER FUNCTION public.reload_epp_stock(p_epp_item_id integer, p_qty integer, p_by integer, p_reason text) OWNER TO postgres;

--
-- TOC entry 273 (class 1255 OID 16438)
-- Name: set_updated_at(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.set_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$;


ALTER FUNCTION public.set_updated_at() OWNER TO postgres;

--
-- TOC entry 285 (class 1255 OID 16440)
-- Name: trg_epp_records_after_insert(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.trg_epp_records_after_insert() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  prev_stock INTEGER;
  new_stock INTEGER;
BEGIN
  IF NEW.epp_item_id IS NOT NULL THEN
    -- bloquear si no hay suficiente (el BEFORE ya lo chequeó con FOR UPDATE)
    SELECT current_stock INTO prev_stock FROM epp_items WHERE id = NEW.epp_item_id FOR UPDATE;
    new_stock := prev_stock - NEW.quantity;
    UPDATE epp_items SET current_stock = new_stock WHERE id = NEW.epp_item_id;

    INSERT INTO epp_stock_movements(
      epp_item_id, movement_type, quantity, previous_stock, new_stock, reason, related_epp_record, created_by
    ) VALUES (
      NEW.epp_item_id, 'out', NEW.quantity, prev_stock, new_stock, NEW.reason, NEW.id, NEW.created_by
    );
  END IF;
  RETURN NULL;
END;
$$;


ALTER FUNCTION public.trg_epp_records_after_insert() OWNER TO postgres;

--
-- TOC entry 287 (class 1255 OID 16860)
-- Name: trg_epp_records_before_insert(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.trg_epp_records_before_insert() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  item RECORD;
  user_name VARCHAR(100);
BEGIN
  IF NEW.epp_item_id IS NOT NULL THEN
    SELECT * INTO item FROM epp_items WHERE id = NEW.epp_item_id FOR UPDATE;
    IF NOT FOUND THEN
      RAISE EXCEPTION 'EPP item % no encontrado', NEW.epp_item_id;
    END IF;

    -- snapshot del nombre
    NEW.equipment_snapshot := item.name;

    -- si price no fue enviado por la app, tomar el price del catálogo
    IF NEW.price IS NULL OR NEW.price = 0 THEN
      NEW.price := item.price;
    END IF;

    -- si no viene quantity, por defecto 1 (ya hay CHECK)
    IF NEW.quantity IS NULL THEN
      NEW.quantity := 1;
    END IF;

    -- verificar stock suficiente (opcional; si prefieres permitir negativas, quita este bloque)
    IF item.current_stock < NEW.quantity THEN
      RAISE EXCEPTION 'Stock insuficiente para % (actual % - requerido %)', item.name, item.current_stock, NEW.quantity;
    END IF;
  END IF;

  -- Capturar el nombre del usuario que crea el registro
  IF NEW.created_by IS NOT NULL THEN
    SELECT display_name INTO user_name FROM app_users WHERE id = NEW.created_by;
    NEW.created_by_username := COALESCE(user_name, 'Usuario desconocido');
  END IF;

  RETURN NEW;
END;
$$;


ALTER FUNCTION public.trg_epp_records_before_insert() OWNER TO postgres;

--
-- TOC entry 288 (class 1255 OID 16861)
-- Name: trg_epp_records_sync_updated_by(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.trg_epp_records_sync_updated_by() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  user_name VARCHAR(100);
BEGIN
  -- Si updated_by fue actualizado, capturar su nombre ususario
  IF NEW.updated_by IS NOT NULL AND (OLD.updated_by IS DISTINCT FROM NEW.updated_by) THEN
    SELECT display_name INTO user_name FROM app_users WHERE id = NEW.updated_by;
    -- Guardar en otro campo si necesitas (opcional)
  END IF;

  RETURN NEW;
END;
$$;


ALTER FUNCTION public.trg_epp_records_sync_updated_by() OWNER TO postgres;

--
-- TOC entry 286 (class 1255 OID 16442)
-- Name: trg_price_audit(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.trg_price_audit() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  IF TG_OP = 'UPDATE' AND OLD.price IS DISTINCT FROM NEW.price THEN
    INSERT INTO price_audit (epp_record_id, changed_by, changed_at, old_price, new_price)
    VALUES (NEW.id, NEW.updated_by, now(), OLD.price, NEW.price);
  END IF;
  RETURN NEW;
END;
$$;


ALTER FUNCTION public.trg_price_audit() OWNER TO postgres;

--
-- TOC entry 289 (class 1255 OID 16964)
-- Name: trg_recalc_all_lifespan(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.trg_recalc_all_lifespan() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    rec          RECORD;
    next_rec     RECORD;
    life_days    INTEGER;
    job_pos_id   INTEGER;
    allowed_date DATE;
BEGIN
    -- Obtener cargo del empleado
    SELECT job_position_id INTO job_pos_id
    FROM employees WHERE id = NEW.employee_id;

    -- Obtener vida útil (específica por cargo o genérica)
    SELECT COALESCE(eijp.life_days, ei.life_days, 0)
    INTO life_days
    FROM epp_items ei
    LEFT JOIN epp_item_job_position eijp
        ON ei.id = eijp.epp_item_id
        AND eijp.job_position_id = job_pos_id
    WHERE ei.id = NEW.epp_item_id;

    -- Iterar todos los registros del mismo empleado+EPP cronológicamente
    FOR rec IN
        SELECT id, delivery_date
        FROM epp_records
        WHERE employee_id = NEW.employee_id
          AND epp_item_id = NEW.epp_item_id
        ORDER BY delivery_date ASC, id ASC
    LOOP
        -- Calcular next_allowed_date para este registro
        IF life_days <= 0 THEN
            allowed_date := rec.delivery_date;
        ELSE
            allowed_date := (rec.delivery_date + (life_days || ' days')::interval)::date;
        END IF;

        -- Buscar el registro SIGUIENTE cronológicamente
        SELECT id, delivery_date INTO next_rec
        FROM epp_records
        WHERE employee_id = NEW.employee_id
          AND epp_item_id = NEW.epp_item_id
          AND (delivery_date > rec.delivery_date
               OR (delivery_date = rec.delivery_date AND id > rec.id))
        ORDER BY delivery_date ASC, id ASC
        LIMIT 1;

        IF next_rec IS NULL THEN
            -- Sin siguiente entrega: este es el último → CUMPLIDO
            UPDATE epp_records
            SET lifespan_status   = 'CUMPLIDO',
                next_allowed_date = allowed_date
            WHERE id = rec.id;
        ELSE
            -- Hay siguiente: INCUMPLIMIENTO si llegó antes del plazo
            UPDATE epp_records
            SET lifespan_status   = CASE
                                        WHEN next_rec.delivery_date < allowed_date
                                        THEN 'INCUMPLIMIENTO'
                                        ELSE 'CUMPLIDO'
                                    END,
                next_allowed_date = allowed_date
            WHERE id = rec.id;
        END IF;
    END LOOP;

    RETURN NEW;
END;
$$;


ALTER FUNCTION public.trg_recalc_all_lifespan() OWNER TO postgres;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- TOC entry 216 (class 1259 OID 16443)
-- Name: app_users; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.app_users (
    id integer NOT NULL,
    username character varying(100) NOT NULL,
    password_hash text NOT NULL,
    display_name character varying(150),
    is_active boolean DEFAULT true,
    created_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.app_users OWNER TO postgres;

--
-- TOC entry 217 (class 1259 OID 16450)
-- Name: app_users_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.app_users_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.app_users_id_seq OWNER TO postgres;

--
-- TOC entry 5049 (class 0 OID 0)
-- Dependencies: 217
-- Name: app_users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.app_users_id_seq OWNED BY public.app_users.id;


--
-- TOC entry 218 (class 1259 OID 16451)
-- Name: employees; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.employees (
    id integer NOT NULL,
    first_name character varying(100) NOT NULL,
    last_name character varying(100) NOT NULL,
    dni character varying(20) NOT NULL,
    area character varying(255) NOT NULL,
    job_position_id integer NOT NULL,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    "position" character varying(255)
);


ALTER TABLE public.employees OWNER TO postgres;

--
-- TOC entry 219 (class 1259 OID 16458)
-- Name: employees_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.employees_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.employees_id_seq OWNER TO postgres;

--
-- TOC entry 5050 (class 0 OID 0)
-- Dependencies: 219
-- Name: employees_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.employees_id_seq OWNED BY public.employees.id;


--
-- TOC entry 220 (class 1259 OID 16459)
-- Name: epp_change_history; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.epp_change_history (
    id integer NOT NULL,
    epp_item_id integer NOT NULL,
    user_id integer,
    change_type character varying(50) NOT NULL,
    old_value character varying(255),
    new_value character varying(255),
    change_timestamp timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    change_description text
);


ALTER TABLE public.epp_change_history OWNER TO postgres;

--
-- TOC entry 221 (class 1259 OID 16465)
-- Name: epp_change_history_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.epp_change_history_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.epp_change_history_id_seq OWNER TO postgres;

--
-- TOC entry 5051 (class 0 OID 0)
-- Dependencies: 221
-- Name: epp_change_history_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.epp_change_history_id_seq OWNED BY public.epp_change_history.id;


--
-- TOC entry 234 (class 1259 OID 16834)
-- Name: epp_item_job_position; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.epp_item_job_position (
    id integer NOT NULL,
    epp_item_id integer NOT NULL,
    job_position_id integer NOT NULL,
    life_days integer DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    CONSTRAINT life_days_check CHECK ((life_days >= 0))
);


ALTER TABLE public.epp_item_job_position OWNER TO postgres;

--
-- TOC entry 233 (class 1259 OID 16833)
-- Name: epp_item_job_position_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.epp_item_job_position_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.epp_item_job_position_id_seq OWNER TO postgres;

--
-- TOC entry 5052 (class 0 OID 0)
-- Dependencies: 233
-- Name: epp_item_job_position_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.epp_item_job_position_id_seq OWNED BY public.epp_item_job_position.id;


--
-- TOC entry 222 (class 1259 OID 16466)
-- Name: epp_items; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.epp_items (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    cargo character varying(100),
    life_days integer,
    price numeric(12,2) DEFAULT 0,
    initial_stock integer DEFAULT 0,
    current_stock integer DEFAULT 0,
    alert_threshold_percent integer DEFAULT 80,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    CONSTRAINT epp_items_alert_threshold_percent_check CHECK (((alert_threshold_percent >= 1) AND (alert_threshold_percent <= 100))),
    CONSTRAINT epp_items_current_stock_check CHECK ((current_stock >= 0)),
    CONSTRAINT epp_items_initial_stock_check CHECK ((initial_stock >= 0)),
    CONSTRAINT epp_items_price_check CHECK ((price >= (0)::numeric))
);


ALTER TABLE public.epp_items OWNER TO postgres;

--
-- TOC entry 223 (class 1259 OID 16479)
-- Name: epp_items_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.epp_items_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.epp_items_id_seq OWNER TO postgres;

--
-- TOC entry 5053 (class 0 OID 0)
-- Dependencies: 223
-- Name: epp_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.epp_items_id_seq OWNED BY public.epp_items.id;


--
-- TOC entry 224 (class 1259 OID 16480)
-- Name: epp_records; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.epp_records (
    id integer NOT NULL,
    employee_id integer NOT NULL,
    equipment character varying(200) NOT NULL,
    quantity integer NOT NULL,
    delivery_date date NOT NULL,
    brand_model character varying(200),
    employee_signature bytea,
    price numeric(12,2) DEFAULT 0,
    created_by integer NOT NULL,
    updated_by integer,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    epp_item_id integer,
    equipment_snapshot character varying(255),
    next_allowed_date date,
    lifespan_status character varying(20) DEFAULT 'CUMPLIDO'::character varying NOT NULL,
    price_at_delivery numeric(10,2),
    life_days_at_delivery integer,
    condition character varying(20) DEFAULT 'Nuevo'::character varying,
    detalle text,
    created_by_username character varying(100),
    reason public.epp_delivery_reason DEFAULT 'DOTACION PLANTA'::public.epp_delivery_reason NOT NULL,
    life_days_by_position integer,
    CONSTRAINT epp_records_price_check CHECK ((price >= (0)::numeric)),
    CONSTRAINT epp_records_quantity_check CHECK ((quantity > 0))
);


ALTER TABLE public.epp_records OWNER TO postgres;

--
-- TOC entry 225 (class 1259 OID 16492)
-- Name: epp_records_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.epp_records_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.epp_records_id_seq OWNER TO postgres;

--
-- TOC entry 5054 (class 0 OID 0)
-- Dependencies: 225
-- Name: epp_records_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.epp_records_id_seq OWNED BY public.epp_records.id;


--
-- TOC entry 226 (class 1259 OID 16493)
-- Name: epp_stock_alerts; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.epp_stock_alerts AS
 SELECT id AS epp_item_id,
    name,
    initial_stock,
    current_stock,
    alert_threshold_percent,
    ((((current_stock)::numeric / (GREATEST(initial_stock, 1))::numeric) * (100)::numeric))::numeric(5,2) AS percent_remaining,
        CASE
            WHEN ((initial_stock > 0) AND ((((current_stock)::numeric / (initial_stock)::numeric) * (100)::numeric) < (alert_threshold_percent)::numeric)) THEN true
            ELSE false
        END AS alert_active
   FROM public.epp_items;


ALTER VIEW public.epp_stock_alerts OWNER TO postgres;

--
-- TOC entry 227 (class 1259 OID 16497)
-- Name: epp_stock_movements; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.epp_stock_movements (
    id integer NOT NULL,
    epp_item_id integer NOT NULL,
    movement_type character varying(10) NOT NULL,
    quantity integer NOT NULL,
    previous_stock integer NOT NULL,
    new_stock integer NOT NULL,
    reason text,
    related_epp_record integer,
    created_by integer,
    created_at timestamp with time zone DEFAULT now(),
    CONSTRAINT epp_stock_movements_movement_type_check CHECK (((movement_type)::text = ANY (ARRAY[('in'::character varying)::text, ('out'::character varying)::text]))),
    CONSTRAINT epp_stock_movements_quantity_check CHECK ((quantity > 0))
);


ALTER TABLE public.epp_stock_movements OWNER TO postgres;

--
-- TOC entry 228 (class 1259 OID 16505)
-- Name: epp_stock_movements_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.epp_stock_movements_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.epp_stock_movements_id_seq OWNER TO postgres;

--
-- TOC entry 5055 (class 0 OID 0)
-- Dependencies: 228
-- Name: epp_stock_movements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.epp_stock_movements_id_seq OWNED BY public.epp_stock_movements.id;


--
-- TOC entry 229 (class 1259 OID 16506)
-- Name: job_positions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.job_positions (
    id integer NOT NULL,
    name character varying(200) NOT NULL,
    is_active boolean DEFAULT true,
    created_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.job_positions OWNER TO postgres;

--
-- TOC entry 230 (class 1259 OID 16511)
-- Name: job_positions_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.job_positions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.job_positions_id_seq OWNER TO postgres;

--
-- TOC entry 5056 (class 0 OID 0)
-- Dependencies: 230
-- Name: job_positions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.job_positions_id_seq OWNED BY public.job_positions.id;


--
-- TOC entry 231 (class 1259 OID 16512)
-- Name: price_audit; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.price_audit (
    id integer NOT NULL,
    epp_record_id integer NOT NULL,
    changed_by integer,
    changed_at timestamp with time zone DEFAULT now(),
    old_price numeric(12,2),
    new_price numeric(12,2)
);


ALTER TABLE public.price_audit OWNER TO postgres;

--
-- TOC entry 232 (class 1259 OID 16516)
-- Name: price_audit_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.price_audit_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.price_audit_id_seq OWNER TO postgres;

--
-- TOC entry 5057 (class 0 OID 0)
-- Dependencies: 232
-- Name: price_audit_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.price_audit_id_seq OWNED BY public.price_audit.id;


--
-- TOC entry 4780 (class 2604 OID 16517)
-- Name: app_users id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.app_users ALTER COLUMN id SET DEFAULT nextval('public.app_users_id_seq'::regclass);


--
-- TOC entry 4783 (class 2604 OID 16518)
-- Name: employees id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employees ALTER COLUMN id SET DEFAULT nextval('public.employees_id_seq'::regclass);


--
-- TOC entry 4786 (class 2604 OID 16519)
-- Name: epp_change_history id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.epp_change_history ALTER COLUMN id SET DEFAULT nextval('public.epp_change_history_id_seq'::regclass);


--
-- TOC entry 4809 (class 2604 OID 16837)
-- Name: epp_item_job_position id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.epp_item_job_position ALTER COLUMN id SET DEFAULT nextval('public.epp_item_job_position_id_seq'::regclass);


--
-- TOC entry 4788 (class 2604 OID 16520)
-- Name: epp_items id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.epp_items ALTER COLUMN id SET DEFAULT nextval('public.epp_items_id_seq'::regclass);


--
-- TOC entry 4795 (class 2604 OID 16521)
-- Name: epp_records id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.epp_records ALTER COLUMN id SET DEFAULT nextval('public.epp_records_id_seq'::regclass);


--
-- TOC entry 4802 (class 2604 OID 16522)
-- Name: epp_stock_movements id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.epp_stock_movements ALTER COLUMN id SET DEFAULT nextval('public.epp_stock_movements_id_seq'::regclass);


--
-- TOC entry 4804 (class 2604 OID 16523)
-- Name: job_positions id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.job_positions ALTER COLUMN id SET DEFAULT nextval('public.job_positions_id_seq'::regclass);


--
-- TOC entry 4807 (class 2604 OID 16524)
-- Name: price_audit id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.price_audit ALTER COLUMN id SET DEFAULT nextval('public.price_audit_id_seq'::regclass);


--
-- TOC entry 5025 (class 0 OID 16443)
-- Dependencies: 216
-- Data for Name: app_users; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.app_users (id, username, password_hash, display_name, is_active, created_at) FROM stdin;
1	administradorEPP	$2a$06$5lYse7cquhNqTr7/rQQpiui2FdbCL0p2n.UJtjNfms22BZSE1zjKq	Administrador EPP	t	2026-01-20 09:14:31.02829-05
4	Diego Zeballos	$2y$06$ZPxnxuVq/E7ZmVXespuXReelE6Nv8Nd54E8YL.jdJ/GLZ8JnnOFDO	Diego Zeballos	t	2026-03-04 08:49:42.189522-05
\.


--
-- TOC entry 5027 (class 0 OID 16451)
-- Dependencies: 218
-- Data for Name: employees; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.employees (id, first_name, last_name, dni, area, job_position_id, created_at, updated_at, "position") FROM stdin;
1	DIEGO ALONSO	ZEBALLOS HUAYNA	72943577	SISTEMAS	1	2026-02-06 08:08:11.398861-05	2026-02-06 08:08:11.398861-05	ADMINISTRACIÓN
2	JEANPIERRE ALEXANDER	ALE ANCCORI	73451161	ASISTENTE LOGISTICO	1	2026-02-06 08:16:18.874725-05	2026-02-06 08:16:18.874725-05	ADMINISTRACIÓN
3	KLAUS RAMIRO	VALENCIA SIANCAS	29550293	GERENTE GENERAL	1	2026-02-06 08:17:14.474823-05	2026-02-06 08:17:14.474823-05	ADMINISTRACIÓN
4	AMELIA	ALVIS MARROQUIN	29563463	ADMINISTRADORA	1	2026-02-06 08:18:21.368212-05	2026-02-06 08:18:21.368212-05	ADMINISTRACIÓN
5	IRMA	CHICATA CRUZ	43475153	ANALISTA COMERCIAL	1	2026-02-06 08:20:22.57029-05	2026-02-06 08:20:22.57029-05	ADMINISTRACIÓN
6	GONZALO ALFREDO	REVILLA CHAVES	29561363	COMPRADOR	1	2026-02-06 08:21:09.248231-05	2026-02-06 08:21:09.248231-05	ADMINISTRACIÓN
7	ANDREA ROSELYN	TITO FLORES	47643061	ASISTENTE CONTABLE	1	2026-02-06 08:22:35.905502-05	2026-02-06 08:22:35.905502-05	ADMINISTRACIÓN
8	JOSHIRA MILAGROS	PURUGUAYA CACERES	74309922	ASISTENTE DE PRODUCCION	1	2026-02-06 08:23:35.817838-05	2026-02-06 08:23:35.817838-05	ADMINISTRACIÓN
9	MARYORI ELIZABETH	CORNEJO CCALLOCONDO	72863457	ENCARGADA DE CAJA Y PAGOS	1	2026-02-06 08:26:10.380582-05	2026-02-06 08:26:10.380582-05	ADMINISTRACIÓN
10	RICARDO JAVIER	PARRAGA OSCANOA	42388467	JEFE DE PRODUCCION	2	2026-02-06 08:27:14.008338-05	2026-02-06 08:27:14.008338-05	JEFE DE PLANTA
11	NELFEN ENRIQUE	LEMASCCA CALDERON	80282706	JEFE DE CONTROL DE CALIDAD	3	2026-02-06 08:28:49.335938-05	2026-02-06 08:28:49.335938-05	CALIDAD
12	ROGER	IMATA CONDORI	71955430	SUP. DE CALIDAD	3	2026-02-06 08:33:13.579775-05	2026-02-06 08:33:13.579775-05	CALIDAD
13	JUAN FERNANDO	ORMACHEA HUAMANI	72393538	ASISTENTE DE CALIDAD	3	2026-02-06 08:34:10.68129-05	2026-02-06 08:34:10.68129-05	CALIDAD
14	ROBERT EDU	MEDINA CRUZ	73339554	ASISTENTE DE CALIDAD	3	2026-02-06 08:35:04.577157-05	2026-02-06 08:35:04.577157-05	CALIDAD
15	ALEX PABLO	TUNCO MAMANI	45247639	SUP. DE SEGURIDAD	4	2026-02-06 08:38:43.689066-05	2026-02-06 08:38:43.689066-05	SUPERVISORES Y PLANIFICACION
16	CARLOS ALBERTO	ENRIQUEZ CHIRINOS	70758996	SUP. DE SEGURIDAD	4	2026-02-06 08:40:42.39785-05	2026-02-06 08:40:42.39785-05	SUPERVISORES Y PLANIFICACION
17	ROBERT ALEXANDER	VALASQUEZ ZARATE	72805858	PLANIFICADOR DE PRODUCCION	4	2026-02-06 08:43:33.739613-05	2026-02-06 08:43:33.739613-05	SUPERVISORES Y PLANIFICACION
18	JIMMI PITER	BUSTAMANTE ALAVE	42135657	CAPATAZ DE MAESTRANZA	5	2026-02-06 08:47:48.620592-05	2026-02-06 08:47:48.620592-05	CAPATAZ DE AREA
19	WILSON	HUARCAYLATA TAYPECAHUANA	42093851	CAPATAZ DE ARMADORES	5	2026-02-06 08:50:58.076453-05	2026-02-06 08:50:58.076453-05	CAPATAZ DE AREA
20	RICHAR RICARDO	LAJO CHOQUE	41764044	CAPATAZ DE SOLDADORES	5	2026-02-06 08:51:36.800667-05	2026-02-06 08:51:36.800667-05	CAPATAZ DE AREA
21	ZAYDA MARIELA	TURPO QUIRO	70539571	CADISTA	6	2026-02-06 08:52:41.127751-05	2026-02-06 08:52:41.127751-05	CADISTAS
22	MARCO ANTONIO	ESPINOZA NORIEGA	42784762	CADISTA	6	2026-02-06 08:55:39.672846-05	2026-02-06 08:55:39.672846-05	CADISTAS
23	ROBINSON MAYER	MAMANI MAMANI	76932532	CADISTA	6	2026-02-06 08:56:15.552379-05	2026-02-06 08:56:15.552379-05	CADISTAS
24	IDOMAR ELISEO	CUZCO DAVILA	29502991	JEFE DE ALMACEN	7	2026-02-06 08:57:32.54046-05	2026-02-06 08:57:32.54046-05	ALMACEN
25	SANTIAGO JOSE	RODRIGUEZ DELGADO	71886008	ASISTENTE DE ALMACEN	7	2026-02-06 08:58:08.18195-05	2026-02-06 08:58:08.18195-05	ALMACEN
26	ARTURO ZENON	CUETO CARPIO	29561592	AXUILIAR DE DESPACHO	8	2026-02-06 08:59:41.184601-05	2026-02-06 08:59:41.184601-05	ACABADO, RECUBRIMIENTO Y DESPACHO
27	RUBEN	CHILO CORIMANYA	29284867	EMBALADOR	8	2026-02-06 09:00:35.50471-05	2026-02-06 09:00:35.50471-05	ACABADO, RECUBRIMIENTO Y DESPACHO
28	UBER PEDRO	BUSTINZA CHURA	40565941	PINTOR	8	2026-02-06 09:01:28.586737-05	2026-02-06 09:01:28.586737-05	ACABADO, RECUBRIMIENTO Y DESPACHO
29	CESAR AUGUSTO	MALAGA OCHOA	22088475	PINTOR	8	2026-02-06 09:02:53.752156-05	2026-02-06 09:02:53.752156-05	ACABADO, RECUBRIMIENTO Y DESPACHO
30	ABEL GRIMALDO	PEREZ QUICARA	73126157	PINTOR	8	2026-02-06 09:03:23.482522-05	2026-02-06 09:03:23.482522-05	ACABADO, RECUBRIMIENTO Y DESPACHO
31	ELVIS WILBERT	HERRERA HUARACHE	40001943	PINTOR	8	2026-02-06 09:04:47.241788-05	2026-02-06 09:04:47.241788-05	ACABADO, RECUBRIMIENTO Y DESPACHO
32	DILVER OSCAR	QUISPE TICONA	42380880	OPERADOR DE GRUA	9	2026-02-06 09:06:06.176847-05	2026-02-06 09:06:06.176847-05	CONDUCTORES
33	RONY CRISTHIAN	DE LA CRUZ PANIURA	48188620	OPERADOR DE GRUA	9	2026-02-06 09:07:39.455929-05	2026-02-06 09:07:39.455929-05	CONDUCTORES
34	FRANK KARLO	CASTRO FONSECA	43036143	CONDUCTOR DE CAMIONETAS	9	2026-02-06 09:08:22.83251-05	2026-02-06 09:08:22.83251-05	CONDUCTORES
35	DENIS MILTON	AGUILAR MERMA	44489227	CONDUCTOR DE CAMIONETAS	9	2026-02-06 09:10:37.441366-05	2026-02-06 09:10:37.441366-05	CONDUCTORES
36	WILLLIAM ALBERTO	PAXI ACUÑA	45336992	CONDUCTOR DE CAMIONETAS	9	2026-02-06 09:11:21.417672-05	2026-02-06 09:11:21.417672-05	CONDUCTORES
37	JUAN	CCASANI AVENDAÑO	80654766	SOLDADOR	10	2026-02-06 09:12:10.679633-05	2026-02-06 09:12:10.679633-05	SOLDADORES
38	CESAR WILBER	PACHA CACERES	43797891	SOLDADOR	10	2026-02-06 09:14:16.081489-05	2026-02-06 09:14:16.081489-05	SOLDADORES
39	MOISES ROGER	NOA CALLA	40582422	SOLDADOR	10	2026-02-06 09:16:11.712117-05	2026-02-06 09:16:11.712117-05	SOLDADORES
40	FRANK REYNALDO	SARAVIA BUENO	46066426	SOLDADOR	10	2026-02-06 09:17:34.241277-05	2026-02-06 09:17:34.241277-05	SOLDADORES
41	JIMMY JULINHO	ZUÑIGA ZUÑIGA	70517118	SOLDADOR	10	2026-02-06 09:18:26.810128-05	2026-02-06 09:18:26.810128-05	SOLDADORES
42	RUDY JHON	VIVANCO LEGUIA	76124763	SOLDADOR	10	2026-02-06 09:19:46.349165-05	2026-02-06 09:19:46.349165-05	SOLDADORES
43	JUNIOR ALEXANDER	PRO ARAGON	77033634	SOLDADOR	10	2026-02-06 09:24:17.915318-05	2026-02-06 09:24:17.915318-05	SOLDADORES
44	RAFAEL	HANCCO CARLO	42333083	SOLDADOR	10	2026-02-06 09:25:00.183863-05	2026-02-06 09:25:00.183863-05	SOLDADORES
45	RONAL ISIDRO	PALOMINO RAMOS	45742527	SOLDADOR	10	2026-02-06 09:26:16.171465-05	2026-02-06 09:26:16.171465-05	SOLDADORES
46	JESUS ROMENEN	RODRIGUEZ GOMEZ	45263523	ARMADORES	11	2026-02-06 09:27:15.753605-05	2026-02-06 09:27:15.753605-05	ARMADORES
47	DAVID VITALIANO	COLQUE SOTO	40888811	ARMADORES	11	2026-02-06 09:29:26.040782-05	2026-02-06 09:29:26.040782-05	ARMADORES
48	ANTHONY FIDEL	PRO ARAGON	45752036	ARMADORES	11	2026-02-06 09:30:15.952233-05	2026-02-06 09:30:15.952233-05	ARMADORES
49	WILVER RODY	TICONA HUARACCALLO	47974557	ARMADORES	11	2026-02-06 09:31:06.104059-05	2026-02-06 09:31:06.104059-05	ARMADORES
50	BRAYAN DAVID	VALDIVIA FIGUEROA	46477119	ARMADORES	11	2026-02-06 09:33:17.054706-05	2026-02-06 09:33:17.054706-05	ARMADORES
51	FLOWER	MAYTA VILCA	70609148	ARMADORES	11	2026-02-06 09:33:50.727057-05	2026-02-06 09:33:50.727057-05	ARMADORES
52	TOMAS	CONDORI PARICANAZA	43152785	ARMADORES	11	2026-02-06 09:35:08.430945-05	2026-02-06 09:35:08.430945-05	ARMADORES
53	JHON MICHAELL	CHURA QUISPE	75779357	ARMADORES	11	2026-02-06 09:35:52.502552-05	2026-02-06 09:35:52.502552-05	ARMADORES
54	DARIO ERNESTO	LIMACHE DE LA CRUZ	45690301	ARMADORES	11	2026-02-06 09:36:35.079288-05	2026-02-06 09:36:35.079288-05	ARMADORES
55	LUCIO JULIAN	ORTEGA MAMANI	80474396	ARMADORES	11	2026-02-06 09:38:31.547369-05	2026-02-06 09:38:31.547369-05	ARMADORES
56	ROGER JESUS	SILVA AGUILAR	46443515	ARMADORES	11	2026-02-06 09:39:29.328531-05	2026-02-06 09:39:29.328531-05	ARMADORES
57	ERICK SMITH	GARCIA MIRANDA	70066399	AYUDANTE DE SOLDADURA	12	2026-02-06 09:40:37.97534-05	2026-02-06 09:40:37.97534-05	AYUDANTES SOLDADORES
58	WILLY	ACNCCASI CASQUINA	47468343	AYUDANTE DE SOLDADURA	12	2026-02-06 09:42:42.105493-05	2026-02-06 09:42:42.105493-05	AYUDANTES SOLDADORES
59	PERCY MOISES	LIMACHE DE LA CRUZ	41919471	AYUDANTE DE SOLDADURA	12	2026-02-06 09:47:30.838971-05	2026-02-06 09:47:30.838971-05	AYUDANTES SOLDADORES
60	RONALD	FLORES BUDIEL	47574909	AYUDANTE DE SOLDADURA	12	2026-02-06 09:50:48.403593-05	2026-02-06 09:50:48.403593-05	AYUDANTES SOLDADORES
61	SEGUNDO GABRIEL	JIMENE MARIÑO	44308256	AYUDANTE DE SOLDADURA	12	2026-02-06 09:51:37.166652-05	2026-02-06 09:51:37.166652-05	AYUDANTES SOLDADORES
62	MICHAEL ALEX	APAZA APAZA	76093253	AYUDANTE DE SOLDADURA	12	2026-02-06 09:52:06.018257-05	2026-02-06 09:52:06.018257-05	AYUDANTES SOLDADORES
63	HENRY GUILLERMO ABRAHAM	HERRERA NAJAR	74399607	AYUDANTE DE SOLDADURA	12	2026-02-06 09:53:27.808391-05	2026-02-06 09:53:27.808391-05	AYUDANTES SOLDADORES
64	FLORENTINO	MUCHICA LAYME	44021003	AYUDANTE DE SOLDADURA	12	2026-02-06 09:54:47.811191-05	2026-02-06 09:54:47.811191-05	AYUDANTES SOLDADORES
65	MACK CRISTIAN GONZAGA	TACO TACO	76042128	AYUDANTE DE SOLDADURA	12	2026-02-06 09:55:18.581791-05	2026-02-06 09:55:18.581791-05	AYUDANTES SOLDADORES
66	JUNIOR SAITH	CHACOLLI SOTO	75376044	AYUDANTE DE SOLDADURA	12	2026-02-06 09:57:22.367752-05	2026-02-06 09:57:22.367752-05	AYUDANTES SOLDADORES
67	MILHUAR CLEBER	PEREZ TACO	47315474	AYUDANTE DE SOLDADURA	12	2026-02-06 09:58:37.006131-05	2026-02-06 09:58:37.006131-05	AYUDANTES SOLDADORES
68	WILBER	PACCOSONCCO GARCIA	41802706	AYUDANTE DE SOLDADURA	12	2026-02-06 09:59:18.604549-05	2026-02-06 09:59:18.604549-05	AYUDANTES SOLDADORES
69	ELISEO	QUISPE CUMPA	24712057	OXICORTISTA	13	2026-02-06 10:04:41.949934-05	2026-02-06 10:04:41.949934-05	HABILITADO
70	ROBERTO SIXTO	ROJAS ARMEJO	73646969	HABILITADOR	13	2026-02-06 10:05:18.509515-05	2026-02-06 10:05:18.509515-05	HABILITADO
71	LUIS RONALD	MENDOZA MENDOZA	42873304	HABILITADOR	13	2026-02-06 10:05:57.456827-05	2026-02-06 10:05:57.456827-05	HABILITADO
72	FABIO	CARCAUSTO VELARDE	73074878	FRESADOR	14	2026-02-06 10:09:15.688721-05	2026-02-06 10:09:15.688721-05	MAESTRANZA
73	ALEXIS PAUL	CURI LLAZA	71958979	FRESADOR	14	2026-02-06 10:11:31.592066-05	2026-02-06 10:11:31.592066-05	MAESTRANZA
74	JOSE GUILLERMO	MOLINA BARRIGA	29724832	FRESADOR	14	2026-02-06 10:12:29.479361-05	2026-02-06 10:12:29.479361-05	MAESTRANZA
75	ANIBAL	CHAUCCA QUISPE	41640456	TORNERO	14	2026-02-06 10:15:03.696924-05	2026-02-06 10:15:03.696924-05	MAESTRANZA
76	LUIS FERNANDO	TACO MENDOZA	75619938	FRESADOR	14	2026-02-06 10:16:37.917561-05	2026-02-06 10:16:37.917561-05	MAESTRANZA
77	RULY HEBER	MAQUE ROJAS	47016167	FRESADOR	14	2026-02-06 10:17:17.941585-05	2026-02-06 10:17:17.941585-05	MAESTRANZA
78	JEYSSON JHENNATHAN	ASCENCIO SOTO	46614257	TORNERO	14	2026-02-06 10:18:18.34946-05	2026-02-06 10:18:18.34946-05	MAESTRANZA
79	ALDO FERNANDO	GARCIA GORDILLO	40710070	TORNERO	14	2026-02-06 10:19:31.709803-05	2026-02-06 10:19:31.709803-05	MAESTRANZA
80	JHON ALEX	LLICAHUA HUAMANI	76019836	PACTICANTE SENATI	14	2026-02-06 10:20:12.062362-05	2026-02-06 10:20:12.062362-05	MAESTRANZA
81	BORIS DE DANTE	MADERA PALOMINO	71288433	PACTICANTE SENATI	14	2026-02-06 10:20:45.488191-05	2026-02-06 10:20:45.488191-05	MAESTRANZA
82	EDILBERTO CLIVER	MAMANI CCAMA	47949932	TORNERO	14	2026-02-06 10:21:29.010626-05	2026-02-06 10:21:29.010626-05	MAESTRANZA
83	PAOLO ALEXIS	MONTES TACO	48751571	FRESADOR	14	2026-02-06 10:23:12.853494-05	2026-02-06 10:23:12.853494-05	MAESTRANZA
84	JOSE FABRIZIO	MANRIQUE GONZALES	60783952	PRACTICANTE SENATI	14	2026-02-06 10:23:48.314729-05	2026-02-06 10:23:48.314729-05	MAESTRANZA
85	BRAYAN GABRIEL	ARCE PAJA	61365746	PRACTICANTE SENATI	14	2026-02-06 10:24:22.589692-05	2026-02-06 10:24:22.589692-05	MAESTRANZA
86	JHORDAN EDDY	QUISPE PARIAPAZA	70907068	TORNERO	14	2026-02-06 10:25:07.679117-05	2026-02-06 10:25:07.679117-05	MAESTRANZA
87	DICK	ARAGON ARAGON	29722368	SOLDADOR INOX	15	2026-02-06 10:26:09.926493-05	2026-02-06 10:26:09.926493-05	SOL INOX
88	JAIME ADOLFO	APAZA CONDORI	29596451	SOLDADOR INOX	15	2026-02-06 10:26:51.165189-05	2026-02-06 10:26:51.165189-05	SOL INOX
89	JULIO CESAR	MAMANI MERCADO	44188005	SOLDADOR INOX	15	2026-02-06 10:27:33.261254-05	2026-02-06 10:27:33.261254-05	SOL INOX
90	ROLANDO	BERDEJO LEYVA	44025207	MECANICO DE MANTENIMIENTO	16	2026-02-06 10:28:46.003034-05	2026-02-06 10:28:46.003034-05	MANTENIMIENTO MECANICO
91	HERNAN EULOGIO	SANCA LLACMA	42213361	MECANICO DE MANTENIMIENTO	16	2026-02-06 10:35:16.380799-05	2026-02-06 10:35:16.380799-05	MANTENIMIENTO MECANICO
92	POL MARCO	LUNA QUISPE	72445496	ELECTRICISTA	17	2026-02-06 10:36:18.642709-05	2026-02-06 10:36:18.642709-05	ELECTRICO
93	CARLOS ANGEL	VILCAPAZA CACERES	73788412	ELECTRICISTA	17	2026-02-06 10:36:48.149072-05	2026-02-06 10:36:48.149072-05	ELECTRICO
94	SIXTO	RAMOS PONCE	29509510	ELECTRICISTA	17	2026-02-06 10:37:24.187072-05	2026-02-06 10:37:24.187072-05	ELECTRICO
95	KEVIN JUNIOR	MARQUEZ BAOS	72750377	ELECTRICISTA	17	2026-02-06 10:38:12.109103-05	2026-02-06 10:38:12.109103-05	ELECTRICO
\.


--
-- TOC entry 5029 (class 0 OID 16459)
-- Dependencies: 220
-- Data for Name: epp_change_history; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.epp_change_history (id, epp_item_id, user_id, change_type, old_value, new_value, change_timestamp, change_description) FROM stdin;
\.


--
-- TOC entry 5042 (class 0 OID 16834)
-- Dependencies: 234
-- Data for Name: epp_item_job_position; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.epp_item_job_position (id, epp_item_id, job_position_id, life_days, created_at, updated_at) FROM stdin;
1	1	8	365	2026-03-02 08:20:22.168205-05	2026-03-02 08:20:22.168205-05
2	1	1	365	2026-03-02 08:20:22.168205-05	2026-03-02 08:20:22.168205-05
3	1	7	365	2026-03-02 08:20:22.168205-05	2026-03-02 08:20:22.168205-05
4	1	11	365	2026-03-02 08:20:22.168205-05	2026-03-02 08:20:22.168205-05
5	1	12	365	2026-03-02 08:20:22.168205-05	2026-03-02 08:20:22.168205-05
6	1	6	365	2026-03-02 08:20:22.168205-05	2026-03-02 08:20:22.168205-05
7	1	3	365	2026-03-02 08:20:22.168205-05	2026-03-02 08:20:22.168205-05
8	1	5	365	2026-03-02 08:20:22.168205-05	2026-03-02 08:20:22.168205-05
9	1	9	365	2026-03-02 08:20:22.168205-05	2026-03-02 08:20:22.168205-05
10	1	17	365	2026-03-02 08:20:22.168205-05	2026-03-02 08:20:22.168205-05
11	1	13	365	2026-03-02 08:20:22.168205-05	2026-03-02 08:20:22.168205-05
12	1	2	365	2026-03-02 08:20:22.168205-05	2026-03-02 08:20:22.168205-05
13	1	14	365	2026-03-02 08:20:22.168205-05	2026-03-02 08:20:22.168205-05
14	1	16	365	2026-03-02 08:20:22.168205-05	2026-03-02 08:20:22.168205-05
15	1	15	365	2026-03-02 08:20:22.168205-05	2026-03-02 08:20:22.168205-05
16	1	10	365	2026-03-02 08:20:22.168205-05	2026-03-02 08:20:22.168205-05
17	1	4	365	2026-03-02 08:20:22.168205-05	2026-03-02 08:20:22.168205-05
\.


--
-- TOC entry 5031 (class 0 OID 16466)
-- Dependencies: 222
-- Data for Name: epp_items; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.epp_items (id, name, cargo, life_days, price, initial_stock, current_stock, alert_threshold_percent, created_at, updated_at) FROM stdin;
1	CASCO TIPO JOCKEY	\N	\N	19.00	50	50	80	2026-03-02 08:14:03.00339-05	2026-03-02 08:14:03.00339-05
\.


--
-- TOC entry 5033 (class 0 OID 16480)
-- Dependencies: 224
-- Data for Name: epp_records; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.epp_records (id, employee_id, equipment, quantity, delivery_date, brand_model, employee_signature, price, created_by, updated_by, created_at, updated_at, epp_item_id, equipment_snapshot, next_allowed_date, lifespan_status, price_at_delivery, life_days_at_delivery, condition, detalle, created_by_username, reason, life_days_by_position) FROM stdin;
\.


--
-- TOC entry 5035 (class 0 OID 16497)
-- Dependencies: 227
-- Data for Name: epp_stock_movements; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.epp_stock_movements (id, epp_item_id, movement_type, quantity, previous_stock, new_stock, reason, related_epp_record, created_by, created_at) FROM stdin;
\.


--
-- TOC entry 5037 (class 0 OID 16506)
-- Dependencies: 229
-- Data for Name: job_positions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.job_positions (id, name, is_active, created_at) FROM stdin;
1	ADMINISTRACIÓN	t	2026-02-06 08:06:01.747416-05
2	JEFE DE PLANTA	t	2026-02-06 08:06:01.748187-05
3	CALIDAD	t	2026-02-06 08:06:01.748479-05
4	SUPERVISORES Y PLANIFICACION	t	2026-02-06 08:06:01.748762-05
5	CAPATAZ DE AREA	t	2026-02-06 08:06:01.749049-05
6	CADISTAS	t	2026-02-06 08:06:01.749331-05
7	ALMACEN	t	2026-02-06 08:06:01.749607-05
8	ACABADO, RECUBRIMIENTO Y DESPACHO	t	2026-02-06 08:06:01.749881-05
9	CONDUCTORES	t	2026-02-06 08:06:01.75017-05
10	SOLDADORES	t	2026-02-06 08:06:01.750452-05
11	ARMADORES	t	2026-02-06 08:06:01.750726-05
12	AYUDANTES SOLDADORES	t	2026-02-06 08:06:01.751004-05
13	HABILITADO	t	2026-02-06 08:06:01.751289-05
14	MAESTRANZA	t	2026-02-06 08:06:01.751559-05
15	SOL INOX	t	2026-02-06 08:06:01.751835-05
16	MANTENIMIENTO MECANICO	t	2026-02-06 08:06:01.752123-05
17	ELECTRICO	t	2026-02-06 08:06:01.752405-05
\.


--
-- TOC entry 5039 (class 0 OID 16512)
-- Dependencies: 231
-- Data for Name: price_audit; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.price_audit (id, epp_record_id, changed_by, changed_at, old_price, new_price) FROM stdin;
\.


--
-- TOC entry 5058 (class 0 OID 0)
-- Dependencies: 217
-- Name: app_users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.app_users_id_seq', 4, true);


--
-- TOC entry 5059 (class 0 OID 0)
-- Dependencies: 219
-- Name: employees_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.employees_id_seq', 95, true);


--
-- TOC entry 5060 (class 0 OID 0)
-- Dependencies: 221
-- Name: epp_change_history_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.epp_change_history_id_seq', 1, false);


--
-- TOC entry 5061 (class 0 OID 0)
-- Dependencies: 233
-- Name: epp_item_job_position_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.epp_item_job_position_id_seq', 17, true);


--
-- TOC entry 5062 (class 0 OID 0)
-- Dependencies: 223
-- Name: epp_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.epp_items_id_seq', 1, true);


--
-- TOC entry 5063 (class 0 OID 0)
-- Dependencies: 225
-- Name: epp_records_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.epp_records_id_seq', 1, false);


--
-- TOC entry 5064 (class 0 OID 0)
-- Dependencies: 228
-- Name: epp_stock_movements_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.epp_stock_movements_id_seq', 1, false);


--
-- TOC entry 5065 (class 0 OID 0)
-- Dependencies: 230
-- Name: job_positions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.job_positions_id_seq', 17, true);


--
-- TOC entry 5066 (class 0 OID 0)
-- Dependencies: 232
-- Name: price_audit_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.price_audit_id_seq', 1, false);


--
-- TOC entry 4823 (class 2606 OID 16526)
-- Name: app_users app_users_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.app_users
    ADD CONSTRAINT app_users_pkey PRIMARY KEY (id);


--
-- TOC entry 4825 (class 2606 OID 16528)
-- Name: app_users app_users_username_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.app_users
    ADD CONSTRAINT app_users_username_key UNIQUE (username);


--
-- TOC entry 4827 (class 2606 OID 16530)
-- Name: employees employees_dni_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employees
    ADD CONSTRAINT employees_dni_key UNIQUE (dni);


--
-- TOC entry 4829 (class 2606 OID 16532)
-- Name: employees employees_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employees
    ADD CONSTRAINT employees_pkey PRIMARY KEY (id);


--
-- TOC entry 4833 (class 2606 OID 16534)
-- Name: epp_change_history epp_change_history_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.epp_change_history
    ADD CONSTRAINT epp_change_history_pkey PRIMARY KEY (id);


--
-- TOC entry 4856 (class 2606 OID 16843)
-- Name: epp_item_job_position epp_item_job_position_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.epp_item_job_position
    ADD CONSTRAINT epp_item_job_position_pkey PRIMARY KEY (id);


--
-- TOC entry 4837 (class 2606 OID 16536)
-- Name: epp_items epp_items_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.epp_items
    ADD CONSTRAINT epp_items_pkey PRIMARY KEY (id);


--
-- TOC entry 4840 (class 2606 OID 16538)
-- Name: epp_records epp_records_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.epp_records
    ADD CONSTRAINT epp_records_pkey PRIMARY KEY (id);


--
-- TOC entry 4847 (class 2606 OID 16540)
-- Name: epp_stock_movements epp_stock_movements_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.epp_stock_movements
    ADD CONSTRAINT epp_stock_movements_pkey PRIMARY KEY (id);


--
-- TOC entry 4850 (class 2606 OID 16542)
-- Name: job_positions job_positions_name_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.job_positions
    ADD CONSTRAINT job_positions_name_key UNIQUE (name);


--
-- TOC entry 4852 (class 2606 OID 16544)
-- Name: job_positions job_positions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.job_positions
    ADD CONSTRAINT job_positions_pkey PRIMARY KEY (id);


--
-- TOC entry 4854 (class 2606 OID 16546)
-- Name: price_audit price_audit_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.price_audit
    ADD CONSTRAINT price_audit_pkey PRIMARY KEY (id);


--
-- TOC entry 4860 (class 2606 OID 16845)
-- Name: epp_item_job_position unique_epp_position; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.epp_item_job_position
    ADD CONSTRAINT unique_epp_position UNIQUE (epp_item_id, job_position_id);


--
-- TOC entry 4830 (class 1259 OID 16547)
-- Name: idx_employees_dni; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_employees_dni ON public.employees USING btree (dni);


--
-- TOC entry 4831 (class 1259 OID 16548)
-- Name: idx_employees_name_lower; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_employees_name_lower ON public.employees USING btree (lower((((first_name)::text || ' '::text) || (last_name)::text)));


--
-- TOC entry 4834 (class 1259 OID 16549)
-- Name: idx_epp_change_history_epp_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_epp_change_history_epp_id ON public.epp_change_history USING btree (epp_item_id);


--
-- TOC entry 4835 (class 1259 OID 16550)
-- Name: idx_epp_change_history_timestamp; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_epp_change_history_timestamp ON public.epp_change_history USING btree (change_timestamp);


--
-- TOC entry 4841 (class 1259 OID 16551)
-- Name: idx_epp_delivery_date; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_epp_delivery_date ON public.epp_records USING btree (delivery_date);


--
-- TOC entry 4842 (class 1259 OID 16552)
-- Name: idx_epp_employee_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_epp_employee_id ON public.epp_records USING btree (employee_id);


--
-- TOC entry 4857 (class 1259 OID 16856)
-- Name: idx_epp_item_job_position_epp_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_epp_item_job_position_epp_id ON public.epp_item_job_position USING btree (epp_item_id);


--
-- TOC entry 4858 (class 1259 OID 16857)
-- Name: idx_epp_item_job_position_job_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_epp_item_job_position_job_id ON public.epp_item_job_position USING btree (job_position_id);


--
-- TOC entry 4838 (class 1259 OID 16553)
-- Name: idx_epp_items_name_lower; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_epp_items_name_lower ON public.epp_items USING btree (lower((name)::text));


--
-- TOC entry 4843 (class 1259 OID 16864)
-- Name: idx_epp_records_created_by_username; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_epp_records_created_by_username ON public.epp_records USING btree (created_by_username);


--
-- TOC entry 4844 (class 1259 OID 16554)
-- Name: idx_epp_records_emp_item_date; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_epp_records_emp_item_date ON public.epp_records USING btree (employee_id, epp_item_id, delivery_date DESC);


--
-- TOC entry 4845 (class 1259 OID 16863)
-- Name: idx_epp_records_reason; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_epp_records_reason ON public.epp_records USING btree (reason);


--
-- TOC entry 4848 (class 1259 OID 16555)
-- Name: idx_stock_movements_epp_idx; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_stock_movements_epp_idx ON public.epp_stock_movements USING btree (epp_item_id, created_at);


--
-- TOC entry 4874 (class 2620 OID 16556)
-- Name: employees trg_employees_updated; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_employees_updated BEFORE UPDATE ON public.employees FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- TOC entry 4875 (class 2620 OID 16559)
-- Name: epp_items trg_epp_items_updated; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_epp_items_updated BEFORE UPDATE ON public.epp_items FOR EACH ROW EXECUTE FUNCTION public.epp_items_set_updated_at();


--
-- TOC entry 4876 (class 2620 OID 16560)
-- Name: epp_records trg_epp_records_after_insert; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_epp_records_after_insert AFTER INSERT ON public.epp_records FOR EACH ROW EXECUTE FUNCTION public.trg_epp_records_after_insert();


--
-- TOC entry 4877 (class 2620 OID 16862)
-- Name: epp_records trg_epp_records_sync_updated_by; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_epp_records_sync_updated_by BEFORE UPDATE ON public.epp_records FOR EACH ROW EXECUTE FUNCTION public.trg_epp_records_sync_updated_by();


--
-- TOC entry 4878 (class 2620 OID 16562)
-- Name: epp_records trg_epp_updated; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_epp_updated BEFORE UPDATE ON public.epp_records FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- TOC entry 4879 (class 2620 OID 16563)
-- Name: epp_records trg_price_audit_after; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_price_audit_after AFTER UPDATE ON public.epp_records FOR EACH ROW EXECUTE FUNCTION public.trg_price_audit();


--
-- TOC entry 4880 (class 2620 OID 16965)
-- Name: epp_records trg_recalc_after_insert; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_recalc_after_insert AFTER INSERT ON public.epp_records FOR EACH ROW EXECUTE FUNCTION public.trg_recalc_all_lifespan();


--
-- TOC entry 4862 (class 2606 OID 16564)
-- Name: epp_change_history epp_change_history_epp_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.epp_change_history
    ADD CONSTRAINT epp_change_history_epp_item_id_fkey FOREIGN KEY (epp_item_id) REFERENCES public.epp_items(id) ON DELETE CASCADE;


--
-- TOC entry 4863 (class 2606 OID 16569)
-- Name: epp_records epp_records_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.epp_records
    ADD CONSTRAINT epp_records_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.app_users(id);


--
-- TOC entry 4864 (class 2606 OID 16574)
-- Name: epp_records epp_records_employee_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.epp_records
    ADD CONSTRAINT epp_records_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE CASCADE;


--
-- TOC entry 4865 (class 2606 OID 16579)
-- Name: epp_records epp_records_epp_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.epp_records
    ADD CONSTRAINT epp_records_epp_item_id_fkey FOREIGN KEY (epp_item_id) REFERENCES public.epp_items(id) ON DELETE SET NULL;


--
-- TOC entry 4866 (class 2606 OID 16584)
-- Name: epp_records epp_records_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.epp_records
    ADD CONSTRAINT epp_records_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.app_users(id);


--
-- TOC entry 4867 (class 2606 OID 16589)
-- Name: epp_stock_movements epp_stock_movements_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.epp_stock_movements
    ADD CONSTRAINT epp_stock_movements_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.app_users(id);


--
-- TOC entry 4868 (class 2606 OID 16594)
-- Name: epp_stock_movements epp_stock_movements_epp_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.epp_stock_movements
    ADD CONSTRAINT epp_stock_movements_epp_item_id_fkey FOREIGN KEY (epp_item_id) REFERENCES public.epp_items(id) ON DELETE CASCADE;


--
-- TOC entry 4869 (class 2606 OID 16599)
-- Name: epp_stock_movements epp_stock_movements_related_epp_record_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.epp_stock_movements
    ADD CONSTRAINT epp_stock_movements_related_epp_record_fkey FOREIGN KEY (related_epp_record) REFERENCES public.epp_records(id) ON DELETE SET NULL;


--
-- TOC entry 4861 (class 2606 OID 16604)
-- Name: employees fk_employees_job_position; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employees
    ADD CONSTRAINT fk_employees_job_position FOREIGN KEY (job_position_id) REFERENCES public.job_positions(id) ON DELETE RESTRICT;


--
-- TOC entry 4872 (class 2606 OID 16846)
-- Name: epp_item_job_position fk_epp_item; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.epp_item_job_position
    ADD CONSTRAINT fk_epp_item FOREIGN KEY (epp_item_id) REFERENCES public.epp_items(id) ON DELETE CASCADE;


--
-- TOC entry 4873 (class 2606 OID 16851)
-- Name: epp_item_job_position fk_job_position; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.epp_item_job_position
    ADD CONSTRAINT fk_job_position FOREIGN KEY (job_position_id) REFERENCES public.job_positions(id) ON DELETE CASCADE;


--
-- TOC entry 4870 (class 2606 OID 16609)
-- Name: price_audit price_audit_changed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.price_audit
    ADD CONSTRAINT price_audit_changed_by_fkey FOREIGN KEY (changed_by) REFERENCES public.app_users(id);


--
-- TOC entry 4871 (class 2606 OID 16614)
-- Name: price_audit price_audit_epp_record_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.price_audit
    ADD CONSTRAINT price_audit_epp_record_id_fkey FOREIGN KEY (epp_record_id) REFERENCES public.epp_records(id) ON DELETE CASCADE;


-- Completed on 2026-03-04 10:37:03

--
-- PostgreSQL database dump complete
--

\unrestrict TY7lRbWiDizOOXQvhgkbdXCeTp5FKv1HuQsPJD9JOPazoFfqcgtWgumeJ1oLsY2

