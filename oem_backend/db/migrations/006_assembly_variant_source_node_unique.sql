-- Prevent duplicate assembly rows for the same variant + source node (rebuild / align safety).
CREATE UNIQUE INDEX IF NOT EXISTS uq_oem_assemblies_variant_source_node
ON oem_assemblies (vehicle_variant_id, source_node_id)
WHERE source_node_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS ix_oem_source_nodes_assembly_aria
ON oem_source_nodes (source_id, aria)
WHERE node_type = 'assembly' AND aria IS NOT NULL AND aria <> '';
