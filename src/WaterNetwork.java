import java.util.ArrayDeque;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.PriorityQueue;
import java.util.Queue;

public class WaterNetwork {

    private final ArrayList<Reservoir> reservoirs;
    private final ArrayList<DemandArea> areas;
    private final ArrayList<Pipeline> pipelines;

    // Graph representation using adjacency list
    private final HashMap<String, ArrayList<String>> graph;

    // Priority Queue for water allocation
    private final PriorityQueue<WaterRequest> waterRequests;

    // FIFO queue for maintenance requests
    private final ArrayDeque<MaintenanceRequest> maintenanceQueue;

    // Stores allocation history
    private final ArrayDeque<String> allocationHistory;

    // Allocation policy
    private final WaterAllocationPolicy allocationPolicy;

    private  int requestCounter;
    private  int maintenanceCounter;

    // Constructor
    public WaterNetwork() {

        reservoirs = new ArrayList<>();
        areas = new ArrayList<>();
        pipelines = new ArrayList<>();

        graph = new HashMap<>();

        allocationPolicy = new PriorityWaterAllocationPolicy();

        waterRequests =
                new PriorityQueue<>(
                        allocationPolicy.getComparator()
                );

        maintenanceQueue = new ArrayDeque<>();

        allocationHistory = new ArrayDeque<>();

        requestCounter = 0;
        maintenanceCounter = 0;
    }

    public List<Reservoir> getReservoirs() {
        return List.copyOf(reservoirs);
    }

    public List<DemandArea> getAreas() {
        return List.copyOf(areas);
    }

    public List<Pipeline> getPipelines() {
        return List.copyOf(pipelines);
    }

    public List<WaterRequest> getWaterRequests() {
        ArrayList<WaterRequest> requests =
                new ArrayList<>(waterRequests);
        requests.sort(allocationPolicy.getComparator());
        return List.copyOf(requests);
    }

    public List<MaintenanceRequest> getMaintenanceRequests() {
        return List.copyOf(maintenanceQueue);
    }

    public List<String> getAllocationHistory() {
        return List.copyOf(allocationHistory);
    }

    // --------------------------------------------------
    // ADD RESERVOIR
    // --------------------------------------------------

    public void addReservoir(Reservoir reservoir) {

        reservoirs.add(reservoir);

        graph.put(
                reservoir.getReservoirId(),
                new ArrayList<>()
        );
    }

    // --------------------------------------------------
    // ADD DEMAND AREA
    // --------------------------------------------------

    public void addArea(DemandArea area) {

        areas.add(area);

        graph.put(
                area.getAreaId(),
                new ArrayList<>()
        );
    }

    // --------------------------------------------------
    // ADD PIPELINE
    // --------------------------------------------------

    public void addPipeline(Pipeline pipeline) {

        pipelines.add(pipeline);

        String from = pipeline.getFrom();
        String to = pipeline.getTo();

        graph.get(from).add(to);
        graph.get(to).add(from);
    }

    // --------------------------------------------------
    // FIND RESERVOIR
    // --------------------------------------------------

    public Reservoir findReservoir(String id) {

        for (Reservoir r : reservoirs) {

            if (r.getReservoirId().equals(id)) {
                return r;
            }
        }

        return null;
    }

    // --------------------------------------------------
    // FIND AREA
    // --------------------------------------------------

    public DemandArea findArea(String id) {

        for (DemandArea area : areas) {

            if (area.getAreaId().equals(id)) {
                return area;
            }
        }

        return null;
    }

    // --------------------------------------------------
    // FIND PIPELINE
    // --------------------------------------------------

    public Pipeline findPipeline(String id) {

        for (Pipeline p : pipelines) {

            if (p.getPipelineId().equals(id)) {
                return p;
            }
        }

        return null;
    }

    // --------------------------------------------------
    // DISPLAY RESERVOIRS
    // --------------------------------------------------

    public void displayReservoirs() {

        System.out.println();
        System.out.println("===== RESERVOIRS =====");

        for (Reservoir r : reservoirs) {

            System.out.println(r);
        }
    }

    // --------------------------------------------------
    // DISPLAY DEMAND AREAS
    // --------------------------------------------------

    public void displayAreas() {

        System.out.println();
        System.out.println("===== DEMAND AREAS =====");

        for (DemandArea area : areas) {

            System.out.println(area);
        }
    }

    // --------------------------------------------------
    // DISPLAY PIPELINES
    // --------------------------------------------------

    public void displayPipelines() {

        System.out.println();
        System.out.println("===== PIPELINES =====");

        for (Pipeline p : pipelines) {

            System.out.println(p);
        }
    }

    // --------------------------------------------------
    // CHECK WORKING PIPELINE CONNECTION
    // --------------------------------------------------

    private boolean isWorkingConnection(
            String from,
            String to) {

        for (Pipeline p : pipelines) {

            if (!p.isWorking()) {
                continue;
            }

            boolean forward =
                    p.getFrom().equals(from)
                    && p.getTo().equals(to);

            boolean backward =
                    p.getFrom().equals(to)
                    && p.getTo().equals(from);

            if (forward || backward) {
                return true;
            }
        }

        return false;
    }

    // --------------------------------------------------
    // BFS REACHABILITY
    // --------------------------------------------------

    public boolean isReachable(
            String start,
            String target) {

        Queue<String> queue =
                new ArrayDeque<>();

        HashSet<String> visited =
                new HashSet<>();

        queue.add(start);
        visited.add(start);

        while (!queue.isEmpty()) {

            String current = queue.remove();

            if (current.equals(target)) {
                return true;
            }

            ArrayList<String> neighbours =
                    graph.get(current);

            if (neighbours == null) {
                continue;
            }

            for (String next : neighbours) {

                if (!visited.contains(next)
                        && isWorkingConnection(
                                current,
                                next)) {

                    visited.add(next);
                    queue.add(next);
                }
            }
        }

        return false;
    }

    // --------------------------------------------------
    // SHOW CONNECTIVITY
    // --------------------------------------------------

    public void showConnectivity() {

        System.out.println();
        System.out.println("===== CONNECTIVITY STATUS =====");

        for (DemandArea area : areas) {

            boolean connected = false;

            for (Reservoir r : reservoirs) {

                if (isReachable(
                        r.getReservoirId(),
                        area.getAreaId())) {

                    connected = true;
                    break;
                }
            }

            if (connected) {

                System.out.println(
                        area.getAreaId()
                        + " - "
                        + area.getName()
                        + " -> CONNECTED"
                );

            } else {

                System.out.println(
                        area.getAreaId()
                        + " - "
                        + area.getName()
                        + " -> DISCONNECTED"
                );
            }
        }
    }

    // --------------------------------------------------
    // ADD WATER REQUEST
    // --------------------------------------------------

    public void addWaterRequest(
            DemandArea area,
            int litres) {

        if (area == null) {

            System.out.println(
                    "Invalid demand area."
            );

            return;
        }

        if (litres <= 0) {

            System.out.println(
                    "Requested litres must be greater than 0."
            );

            return;
        }

        requestCounter++;

        WaterRequest request =
                new WaterRequest(
                        area,
                        litres,
                        requestCounter
                );

        waterRequests.add(request);

        System.out.println(
                "Water request added for "
                + area.getName()
                + " - "
                + litres
                + " L"
        );
    }

    // --------------------------------------------------
    // FIND USABLE RESERVOIR
    // --------------------------------------------------

    private Reservoir findUsableReservoir(
            DemandArea area) {

        for (Reservoir r : reservoirs) {

            if (r.getRemainingSupply() > 0
                    && isReachable(
                    r.getReservoirId(),
                    area.getAreaId())) {

                return r;
            }
        }

        return null;
    }

    // --------------------------------------------------
    // ALLOCATE WATER
    // --------------------------------------------------

    public void allocateWater() {

        System.out.println();
        System.out.println("===== WATER ALLOCATION =====");

        if (waterRequests.isEmpty()) {

            System.out.println(
                    "No water requests available."
            );

            return;
        }

        while (!waterRequests.isEmpty()) {

            WaterRequest request =
                    waterRequests.poll();

            DemandArea area =
                    request.getArea();

            Reservoir reservoir =
                    findUsableReservoir(area);

            // No reachable reservoir
            if (reservoir == null) {

                System.out.println(
                        area.getName()
                        + " -> No usable reservoir."
                );

                allocationHistory.add(
                        area.getName()
                        + " : 0 L allocated - DISCONNECTED"
                );

                continue;
            }

            int required =
                    Math.min(
                            request.getRequestedLitres(),
                            area.getUnmetWater()
                    );

            int available =
                    reservoir.getRemainingSupply();

            int allocated =
                    Math.min(
                            required,
                            available
                    );

            // Allocate water
            if (allocated > 0) {

                reservoir.allocateWater(
                        allocated
                );

                area.allocateWater(
                        allocated
                );

                allocationHistory.add(
                        area.getName()
                        + " <- "
                        + allocated
                        + " L from "
                        + reservoir.getName()
                );

                System.out.println(
                        area.getName()
                        + " received "
                        + allocated
                        + " L from "
                        + reservoir.getName()
                );
            }

            // Show unmet demand
            if (area.getUnmetWater() > 0) {

                System.out.println(
                        "Unmet demand for "
                        + area.getName()
                        + ": "
                        + area.getUnmetWater()
                        + " L"
                );
            }
        }
    }

    // --------------------------------------------------
    // FAIL PIPELINE
    // --------------------------------------------------

    public void failPipeline(
            String pipelineId) {

        Pipeline pipeline =
                findPipeline(pipelineId);

        if (pipeline == null) {

            System.out.println(
                    "Pipeline not found."
            );

            return;
        }

        if (!pipeline.isWorking()) {

            System.out.println(
                    pipelineId
                    + " is already failed."
            );

            return;
        }

        pipeline.markFailed();

        System.out.println(
                pipelineId
                + " has been marked FAILED."
        );

        addMaintenanceRequest(
                pipelineId,
                "Pipeline failure"
        );
    }

    // --------------------------------------------------
    // REPAIR PIPELINE
    // --------------------------------------------------

    public void repairPipeline(
            String pipelineId) {

        Pipeline pipeline =
                findPipeline(pipelineId);

        if (pipeline == null) {

            System.out.println(
                    "Pipeline not found."
            );

            return;
        }

        if (pipeline.isWorking()) {

            System.out.println(
                    pipelineId
                    + " is already working."
            );

            return;
        }

        pipeline.repair();

        System.out.println(
                pipelineId
                + " has been repaired."
        );
    }

    // --------------------------------------------------
    // ADD MAINTENANCE REQUEST
    // --------------------------------------------------

    public void addMaintenanceRequest(
            String pipelineId,
            String issue) {

        maintenanceCounter++;

        MaintenanceRequest request =
                new MaintenanceRequest(
                        pipelineId,
                        issue,
                        maintenanceCounter
                );

        maintenanceQueue.add(request);

        System.out.println(
                "Maintenance request added."
        );
    }

    // --------------------------------------------------
    // DISPLAY MAINTENANCE QUEUE
    // --------------------------------------------------

    public void displayMaintenanceQueue() {

        System.out.println();
        System.out.println(
                "===== MAINTENANCE QUEUE ====="
        );

        if (maintenanceQueue.isEmpty()) {

            System.out.println(
                    "No pending maintenance requests."
            );

            return;
        }

        for (MaintenanceRequest request
                : maintenanceQueue) {

            System.out.println(request);
        }
    }

    // --------------------------------------------------
    // DISPLAY ALLOCATION HISTORY
    // --------------------------------------------------

    public void displayAllocationHistory() {

        System.out.println();
        System.out.println(
                "===== ALLOCATION HISTORY ====="
        );

        if (allocationHistory.isEmpty()) {

            System.out.println(
                    "No allocation history."
            );

            return;
        }

        for (String record
                : allocationHistory) {

            System.out.println(record);
        }
    }

    // --------------------------------------------------
    // RESET DAILY SUPPLY
    // --------------------------------------------------

    public void resetDailySupply() {

        for (Reservoir r : reservoirs) {

            r.resetDailySupply();
        }

        for (DemandArea area : areas) {

            area.resetDailyAllocation();
        }

        waterRequests.clear();

        allocationHistory.clear();

        System.out.println();
        System.out.println(
                "New simulation day started."
        );

        System.out.println(
                "Reservoir supplies and area allocations have been reset."
        );
    }
}