import express from 'express';
import cors from 'cors';
import agentsRouter from './routes/agents';

const app = express();
const PORT = process.env.PORT || 3001;

app.use(cors());
app.use(express.json());

app.use('/api', agentsRouter);

app.get('/health', (_req, res) => {
  res.json({
    status: 'ok',
    service: 'NEXUS Agents API',
    version: '1.0.0',
    timestamp: new Date().toISOString(),
  });
});

app.listen(PORT, () => {
  console.log(`NEXUS Agents API running on http://localhost:${PORT}`);
  console.log('Agents available:');
  console.log('  - Compliance & Risk Agent');
  console.log('  - Routing Agent');
  console.log('  - Execution & Ledger Agent');
  console.log('  - Nexus Intelligence Orchestrator');
});
