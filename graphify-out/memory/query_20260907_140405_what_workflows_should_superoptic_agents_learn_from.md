---
type: "query"
date: "2026-09-07T14:04:05.595931+00:00"
question: "What workflows should SuperOptic agents learn from the PHP system?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["network", "billing", "inventory", "monitoring", "messaging", "ai"]
---

# Q: What workflows should SuperOptic agents learn from the PHP system?

## Answer

The canonical domains are NOC network health, device onboarding and learning, alarm to ticket response, billing and RADIUS maintenance, inventory and customer equipment, communication queue delivery, and crew proposal to human review. Each must separate read-only evidence, proposal operations, and approval-required writes. The machine-readable map is config/agent_workflows.json exposed by api/agent-ops/workflows.php; it is descriptive and never grants execution.

## Outcome

- Signal: useful

## Source Nodes

- network
- billing
- inventory
- monitoring
- messaging
- ai