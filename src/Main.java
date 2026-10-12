import java.util.Scanner;

public class Main {

    public static void createSampleData(WaterNetwork network) {

        // ==========================================
        // RESERVOIRS
        // ==========================================

        network.addReservoir(
                new Reservoir("R1", "North Reservoir", 5000)
        );

        network.addReservoir(
                new Reservoir("R2", "South Reservoir", 3000)
        );


        // ==========================================
        // 10 DEMAND AREAS
        // ==========================================

        network.addArea(
                new ResidentialArea(
                        "A1", "Greenview Homes", 1500, "Normal"
                )
        );

        network.addArea(
                new ResidentialArea(
                        "A2", "Lakeside Villas", 2000, "High"
                )
        );

        network.addArea(
                new CriticalInstitution(
                        "A3", "City Hospital", 3000, "Critical"
                )
        );

        network.addArea(
                new ResidentialArea(
                        "A4", "Rivergate Apartments", 1800, "Normal"
                )
        );

        network.addArea(
                new ResidentialArea(
                        "A5", "Sunrise Colony", 1200, "High"
                )
        );

        network.addArea(
                new CriticalInstitution(
                        "A6", "Central Fire Station", 1800, "Critical"
                )
        );

        network.addArea(
                new ResidentialArea(
                        "A7", "Hillview Nagar", 1400, "Normal"
                )
        );

        network.addArea(
                new ResidentialArea(
                        "A8", "Maple Residency", 1600, "High"
                )
        );

        network.addArea(
                new ResidentialArea(
                        "A9", "Riverside Colony", 1300, "Normal"
                )
        );

        network.addArea(
                new ResidentialArea(
                        "A10", "Green Park", 1700, "High"
                )
        );


        // ==========================================
        // 15 PIPELINES
        // ==========================================

        network.addPipeline(
                new Pipeline("P1", "R1", "A1")
        );

        network.addPipeline(
                new Pipeline("P2", "R1", "A2")
        );

        network.addPipeline(
                new Pipeline("P3", "R1", "A3")
        );

        network.addPipeline(
                new Pipeline("P4", "A1", "A4")
        );

        network.addPipeline(
                new Pipeline("P5", "A2", "A5")
        );

        network.addPipeline(
                new Pipeline("P6", "A3", "A6")
        );

        network.addPipeline(
                new Pipeline("P7", "A4", "A7")
        );

        network.addPipeline(
                new Pipeline("P8", "A5", "A8")
        );

        network.addPipeline(
                new Pipeline("P9", "A6", "A9")
        );

        network.addPipeline(
                new Pipeline("P10", "A7", "A10")
        );

        network.addPipeline(
                new Pipeline("P11", "R2", "A6")
        );

        network.addPipeline(
                new Pipeline("P12", "R2", "A8")
        );

        network.addPipeline(
                new Pipeline("P13", "A7", "A8")
        );

        network.addPipeline(
                new Pipeline("P14", "A9", "A10")
        );

        network.addPipeline(
                new Pipeline("P15", "A3", "A8")
        );
    }

    public static void main(String[] args) {

        try (Scanner scanner = new Scanner(System.in)) {

        WaterNetwork network =
                new WaterNetwork();

        createSampleData(network);

        int choice;

        do {

            System.out.println(
                    "\n======================================"
            );

            System.out.println(
                    " SMART WATER DISTRIBUTION MANAGEMENT"
            );

            System.out.println(
                    "======================================"
            );

            System.out.println(
                    "1. Display Reservoirs"
            );

            System.out.println(
                    "2. Display Demand Areas"
            );

            System.out.println(
                    "3. Display Pipelines"
            );

            System.out.println(
                    "4. Check Connectivity"
            );

            System.out.println(
                    "5. Add Water Request"
            );

            System.out.println(
                    "6. Allocate Water"
            );

            System.out.println(
                    "7. Fail Pipeline"
            );

            System.out.println(
                    "8. Repair Pipeline"
            );

            System.out.println(
                    "9. View Maintenance Queue"
            );

            System.out.println(
                    "10. View Allocation History"
            );

            System.out.println(
                    "11. Reset Daily Supply"
            );

            System.out.println(
                    "12. Exit"
            );

            System.out.print(
                    "\nEnter your choice: "
            );

            choice = scanner.nextInt();

                        switch (choice) {
                                case 1 -> network.displayReservoirs();
                                case 2 -> network.displayAreas();
                                case 3 -> network.displayPipelines();
                                case 4 -> network.showConnectivity();
                                case 5 -> {
                    System.out.print(
                            "Enter area ID: "
                    );

                    String areaId =
                            scanner.next();

                    DemandArea area =
                            network.findArea(areaId);

                    if (area == null) {

                        System.out.println(
                                "Area not found."
                        );

                    } else {

                        System.out.print(
                                "Enter requested litres: "
                        );

                        int litres =
                                scanner.nextInt();

                        network.addWaterRequest(
                                area,
                                litres
                        );
                    }

                                }
                                case 6 -> network.allocateWater();
                                case 7 -> {
                    System.out.print(
                            "Enter pipeline ID: "
                    );

                    String failedId =
                            scanner.next();

                    network.failPipeline(
                            failedId
                    );
                }
                case 8 -> {
                    System.out.print(
                            "Enter pipeline ID: "
                    );

                    String repairId =
                            scanner.next();

                    network.repairPipeline(
                            repairId
                    );
                                }
                                case 9 -> network.displayMaintenanceQueue();
                                case 10 -> network.displayAllocationHistory();
                                case 11 -> network.resetDailySupply();
                                case 12 -> {
                    System.out.println(
                            "Exiting application..."
                    );
                                }
                                default -> {
                    System.out.println(
                            "Invalid choice."
                    );
                                }
            }

        } while (choice != 12);

                }
    }
}