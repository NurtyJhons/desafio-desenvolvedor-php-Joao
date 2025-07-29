# API para Processamento de Arquivos B3 - Documentação

## Visão Geral

API desenvolvida em Laravel para processar arquivos CSV/Excel contendo dados da B3 (Brasil, Bolsa, Balcão). O sistema permite upload de arquivos, histórico de uploads e busca de instrumentos financeiros com cache e processamento assíncrono.

## Tecnologias Utilizadas

- **Framework**: Laravel (PHP)
- **Banco de Dados NoSQL**: MongoDB
- **Cache**: Redis
- **Autenticação**: Laravel Sanctum
- **Filas**: Laravel Queue (Database)
- **Processamento**: Jobs assíncronos

## Recursos Implementados

### ✅ Funcionalidades Principais
- Upload de arquivos CSV/Excel
- Prevenção de arquivos duplicados (hash SHA256)
- Histórico de uploads com filtros
- Busca de instrumentos com filtros opcionais
- Paginação de resultados

### ✅ Recursos Avançados (Bônus)
- **NoSQL**: MongoDB para armazenamento de dados
- **Cache**: Redis para otimização de consultas
- **Autenticação**: Laravel Sanctum
- **Filas**: Processamento assíncrono de arquivos grandes
- **Tratamento UTF-8**: Correção automática de encoding

## Estrutura da API

### Autenticação

A API utiliza autenticação via tokens (Laravel Sanctum). Todos os endpoints principais requerem autenticação.

#### Endpoints de Autenticação

**Registro de Usuário**
```http
POST /api/register

Content-Type: application/json

{
    "name": "Nome do Usuário",
    "email": "usuario@email.com",
    "password": "senha123"
}
```

**Login**
```http
POST /api/login

Content-Type: application/json

{
    "email": "usuario@email.com",
    "password": "senha123"
}
```

**Resposta do Login:**
```json
{
    "token": "1|abc123token456..."
}
```

### Endpoints Principais

Todos os endpoints a seguir requerem o header de autenticação no Postman:
```
Authorization: Bearer {token}
```

#### 1. Upload de Arquivo

**Endpoint:** `POST /api/upload`

**Descrição:** Faz upload de arquivos CSV ou Excel para processamento.

**Parâmetros para envio do Upload:**
- `arquivo` (file, required): Arquivo CSV ou XLXS

**Resposta de Sucesso (201):**
```json
{
    "mensagem": "Upload recebido. O processamento será feito em background."
}
```

**Possíveis Erros:**
- `400`: Arquivo inválido
- `409`: Arquivo já foi enviado anteriormente
- `500`: Falha ao salvar o arquivo

#### 2. Histórico de Uploads

**Endpoint:** `GET /api/uploads`

**Descrição:** Lista histórico de uploads com filtros opcionais.

**Exemplo de Requisição no Postman:**
```bash
GET "http://localhost:8000/api/uploads" 

Headers -> "Authorization: Bearer {token}"
```

**Resposta:**
```json
[
    {
        "filename": "dados_b3.csv",
        "hash": "abc123...",
        "caminho": "private/uploads/dados_b3.csv",
        "uploaded_at": "2024-08-22T10:30:00.000Z",
        "updated_at": "2024-08-22T10:30:00.000Z",
        "created_at": "2024-08-22T10:30:00.000Z",
        "id": "66c7a1b2c3d4e5f6g7h8i9j0",
    }
]
```

#### 3. Buscar Instrumentos

**Endpoint:** `GET /api/instrumentos_buscar`

**Descrição:** Busca instrumentos financeiros com filtros opcionais e paginação.

**Parâmetros opcionais:**
- `TckrSymb` (string, optional): Código do ticker (ex: AMZO34)
- `RptDt` (date, optional): Data de referência (formato: YYYY-MM-DD)
- `page` (integer, optional): Página (padrão: 1)

**Exemplos de Requisição no Postman:**
```bash
GET "http://localhost:8000/api/instrumentos_buscar?TckrSymb=AMZO34&RptDt=2024-08-22"

GET "http://localhost:8000/api/instrumentos_buscar?page=1800"

Headers -> "Authorization: Bearer {token}"
```

**Resposta:**
```json
{
    "current_page": 1,
    "data": [
        {
            "RptDt": "2024-08-22",
            "TckrSymb": "AMZO34",
            "MktNm": "EQUITY-CASH",
            "SctyCtgyNm": "BDR",
            "ISIN": "BRAMZOBDR002",
            "CrpnNm": "AMAZON.COM, INC",
            "dados_completos": {
                "RptDt": "2024-08-22",
                "TckrSymb": "AMZO34",
                "MktNm": "EQUITY-CASH",
                "SctyCtgyNm": "BDR",
                "ISIN": "BRAMZOBDR002",
                "CrpnNm": "AMAZON.COM, INC"
            }
        }
    ],
    "first_page_url": "http://localhost:8000/api/instrumentos_buscar?page=1",
    "from": 1,
    "last_page": 1,
    "last_page_url": "http://localhost:8000/api/instrumentos_buscar?page=1",
    "links": [...],
    "next_page_url": null,
    "path": "http://localhost:8000/api/instrumentos_buscar",
    "per_page": 10,
    "prev_page_url": null,
    "to": 1,
    "total": 1
}
```

#### 4. Apagar Upload (Adicional)

**Endpoint:** `DELETE /api/upload/{id}`

**Descrição:** Remove um upload e todos os dados associados a ele.

**Exemplo de Requisição:**
```bash
DELETE http://localhost:8000/api/upload/66c7a1b2c3d4e5f6g7h8i9j0

Headers -> "Authorization: Bearer {token}"
```

**Resposta:**
```json
{
    "mensagem": "Upload e dados associados apagados com sucesso."
}
```

## Configuração do Ambiente

### Pré-requisitos

- PHP 8.3
- Composer (Laragon)
- MongoDB
- Redis
- MySQL (para queue)

### Variáveis de Ambiente (.env)

```env
# Aplicação Laravel
APP_NAME=Laravel
APP_ENV=local
APP_KEY=base64:UJXA84Zgj1h5yqvp2XDPM5EKG8eFwPgYQ5jIBrb3YlM=
APP_DEBUG=true
APP_URL=http://localhost

# Banco MySQL (para autenticação e filas)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=desafio_laravel
DB_USERNAME=root
DB_PASSWORD=

# MongoDB (dados principais)
MONGO_DB_HOST=127.0.0.1
MONGO_DB_PORT=27017
MONGO_DB_DATABASE=desafio_mongo
MONGO_DB_USERNAME=
MONGO_DB_PASSWORD=
DB_MONGO_AUTHDATABASE=admin

# Redis (cache)
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
CACHE_DRIVER=redis

# Filas
QUEUE_CONNECTION=database
```

### Instalação no Laravel

1. **Clone o repositório e o acesse:**
```bash
git clone https://github.com/NurtyJhons/desafio-desenvolvedor-php-Joao.git

cd laravel-api
```

2. **Faça as instalações necessárias:**
```bash
# Instalador do Laragon:

https://laragon.org/download

# Guia para instalar e implantar a extensão do Redis no Laragon:

https://dev.to/dendihandian/installing-php-redis-extension-on-laragon-2mp3

# Em seguida, vá no Laragon, clique com o botão direito, vá em PHP, e selecione 'php.ini'. Nele, adicione a seguinte linha:

extension=redis

# Guia para instalar e implantar a extensão do MongoDB no Laragon:

https://dev.to/dendihandian/mongodb-on-laragon-hog

# Em seguida, vá no Laragon, clique com o botão direito, vá em PHP, e selecione 'php.ini'. Nele, adicione a seguinte linha:

extension=php_mongodb

# GUI do MongoDB:

https://www.mongodb.com/try/download/compass
```

4. **Inicie os serviços:**
```bash
# Servidor Laravel
php artisan serve

# Worker para processar filas
php artisan queue:work

# Rodar o Cache
redis-server.exe
```

## Arquitetura do Sistema

### Fluxo de Processamento

1. **Upload**: Arquivo é enviado via POST /api/upload
2. **Validação**: Sistema verifica se arquivo já existe (hash SHA256)
3. **Armazenamento**: Arquivo é salvo localmente
4. **Fila**: Job é despachado para processamento assíncrono
5. **Processamento**: CSV é lido e dados são inseridos no MongoDB
6. **Cache**: Resultados são cacheados no Redis

### Estrutura de Dados

#### Collection: uploads_no_sql
```json
{
    "_id": "ObjectId",
    "filename": "string",
    "hash": "string",
    "caminho": "string", 
    "uploaded_at": "datetime",
    "created_at": "datetime",
    "updated_at": "datetime"
}
```

#### Collection: instrumentos_no_sql
```json
{
    "_id": "ObjectId",
    "upload_id": "string",
    "RptDt": "date",
    "TckrSymb": "string",
    "MktNm": "string", 
    "SctyCtgyNm": "string",
    "ISIN": "string",
    "CrpnNm": "string",
    "dados_json": "object",
    "created_at": "datetime",
    "updated_at": "datetime"
}
```

## Otimizações Implementadas

### Performance
- **Processamento em lotes**: Inserção de 500 registros por vez
- **Cache Redis**: Consultas frequentes são cacheadas por 60 segundos
- **Filas assíncronas**: Processamento não bloqueia requisições

### Tratamento de Dados
- **Encoding UTF-8**: Correção automática de caracteres especiais
- **Validação de datas**: Suporte a formatos DD/MM/YYYY e YYYY-MM-DD
- **Limpeza de dados**: Remoção de caracteres especiais problemáticos
- **Fallback**: Inserção individual em caso de falha do lote

### Segurança
- **Hash de arquivos**: Prevenção de uploads duplicados
- **Autenticação**: Todos endpoints protegidos por token com o Sanctum
- **Validação**: Tipos de arquivo e tamanhos controlados

---

**Desenvolvido para teste técnico - Vaga Desenvolvedor Backend Junior**  
**Tecnologias**: Laravel, MongoDB, Redis, Sanctum, Queue