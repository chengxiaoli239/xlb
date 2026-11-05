import 'dotenv/config';
import { createDatabase } from './database.js';
import { createApp } from './app.js';

if (process.env.NODE_ENV === 'production') throw new Error('This local-development service must not be started in production mode.');
const db = createDatabase(process.env.DATABASE_PATH ?? './data/xlb-local.sqlite');
const app = createApp(db);
const port = Number(process.env.PORT ?? 4174);
app.listen(port, '127.0.0.1', () => console.log('Local API listening at http://127.0.0.1:' + port));
