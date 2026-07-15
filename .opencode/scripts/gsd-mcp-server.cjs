#!/usr/bin/env node
'use strict';

const { spawnSync } = require('node:child_process');
const path = require('node:path');
const readline = require('node:readline');
const fs = require('node:fs');

const toolsPath = path.resolve(__dirname, '..', 'gsd-core', 'bin', 'gsd-tools.cjs');

const tools = [
  {
    name: 'gsd_invoke_command',
    description: 'Invoke a GSD CLI command. Use init/new-project for project initialization context; project/new is accepted as a compatibility alias.',
    inputSchema: {
      type: 'object',
      properties: {
        family: { type: 'string', description: 'Command family, such as init, query, state, or phase.' },
        subcommand: { type: 'string', description: 'Subcommand name.' },
        args: { type: 'array', items: { type: 'string' }, description: 'Positional arguments.' },
      },
      required: ['family', 'subcommand'],
    },
  },
  {
    name: 'gsd_read_state',
    description: 'Read a .planning state file.',
    inputSchema: {
      type: 'object',
      properties: { path: { type: 'string', description: 'Absolute path under .planning/.' } },
      required: ['path'],
    },
  },
  {
    name: 'gsd_write_state',
    description: 'Write a .planning state file.',
    inputSchema: {
      type: 'object',
      properties: {
        path: { type: 'string', description: 'Absolute path under .planning/.' },
        content: { type: 'string', description: 'File content.' },
      },
      required: ['path', 'content'],
    },
  },
];

function response(id, result) {
  if (id === undefined) return null;
  return { jsonrpc: '2.0', id, result };
}

function error(id, code, message) {
  if (id === undefined) return null;
  return { jsonrpc: '2.0', id, error: { code, message } };
}

function toolResult(text, isError = false) {
  return { isError, content: [{ type: 'text', text }] };
}

function invokeCommand(arguments_, cwd) {
  let { family, subcommand, args = [] } = arguments_ || {};
  if (family === 'project' && subcommand === 'new') {
    family = 'init';
    subcommand = 'new-project';
  }
  if (typeof family !== 'string' || typeof subcommand !== 'string') {
    return toolResult('gsd_invoke_command requires string "family" and "subcommand".', true);
  }
  if (!Array.isArray(args) || args.some(arg => typeof arg !== 'string')) {
    return toolResult('gsd_invoke_command requires "args" to be an array of strings.', true);
  }
  if (args.some(arg => arg === '--cwd' || arg.startsWith('--cwd='))) {
    return toolResult('gsd_invoke_command does not allow --cwd in "args".', true);
  }

  const result = spawnSync(process.execPath, [toolsPath, family, subcommand, ...args, '--cwd', cwd, '--raw', '--json-errors'], {
    cwd,
    encoding: 'utf8',
    timeout: 30_000,
  });
  if (result.error) return toolResult(result.error.message, true);
  if (result.status !== 0) return toolResult(result.stderr || result.stdout || `dispatch failed (exit ${result.status})`, true);
  return toolResult(result.stdout);
}

function planningRoot(cwd) {
  let directory = fs.realpathSync(cwd);
  while (true) {
    const candidate = path.join(directory, '.planning');
    try {
      if (fs.statSync(candidate).isDirectory()) return fs.realpathSync(candidate);
    } catch {
      // Keep walking until the project planning directory is found.
    }
    const parent = path.dirname(directory);
    if (parent === directory) throw new Error('No .planning directory found for this project.');
    directory = parent;
  }
}

function statePathInsidePlanning(statePath, cwd) {
  if (!path.isAbsolute(statePath)) throw new Error('State path must be absolute.');
  const root = planningRoot(cwd);
  const target = path.resolve(statePath);
  const parent = fs.realpathSync(path.dirname(target));
  const canonicalTarget = fs.existsSync(target) ? fs.realpathSync(target) : path.join(parent, path.basename(target));
  const relative = path.relative(root, canonicalTarget);
  if (!relative || relative.startsWith(`..${path.sep}`) || path.isAbsolute(relative)) {
    throw new Error('State path must be a file under the project .planning directory.');
  }
  return target;
}

function callTool(name, arguments_, cwd) {
  if (name === 'gsd_invoke_command') return invokeCommand(arguments_, cwd);
  const { path: statePath, content } = arguments_ || {};
  if (typeof statePath !== 'string') return toolResult(`${name} requires string "path".`, true);
  try {
    const safeStatePath = statePathInsidePlanning(statePath, cwd);
    if (name === 'gsd_read_state') return toolResult(fs.readFileSync(safeStatePath, 'utf8'));
    if (name === 'gsd_write_state') {
      if (typeof content !== 'string') return toolResult('gsd_write_state requires string "content".', true);
      fs.writeFileSync(safeStatePath, content, 'utf8');
      return toolResult(JSON.stringify({ ok: true, path: safeStatePath }));
    }
  } catch (err) {
    return toolResult(err instanceof Error ? err.message : String(err), true);
  }
  return toolResult(`Unknown tool: ${name}`, true);
}

function handle(request) {
  if (!request || typeof request !== 'object') return error(null, -32600, 'Invalid Request.');
  const { id, method } = request;
  if (method === 'initialize') {
    return response(id, {
      protocolVersion: '2024-11-05',
      capabilities: { tools: {} },
      serverInfo: { name: 'gsd-core', version: 'local' },
    });
  }
  if (method === 'ping') return response(id, {});
  if (method === 'tools/list') return response(id, { tools });
  if (method === 'tools/call') {
    const params = request.params || {};
    if (typeof params.name !== 'string') return error(id, -32602, 'tools/call requires string "name".');
    if (!tools.some(tool => tool.name === params.name)) return error(id, -32602, `Unknown tool: ${params.name}`);
    return response(id, callTool(params.name, params.arguments, process.cwd()));
  }
  return id === undefined ? null : error(id, -32601, `Method not found: ${method || '(empty)'}.`);
}

const input = readline.createInterface({ input: process.stdin, crlfDelay: Infinity });
input.on('line', line => {
  if (!line.trim()) return;
  try {
    const result = handle(JSON.parse(line));
    if (result) process.stdout.write(`${JSON.stringify(result)}\n`);
  } catch {
    process.stdout.write(`${JSON.stringify(error(null, -32700, 'Parse error.'))}\n`);
  }
});
